<?php

declare(strict_types=1);

namespace App\Services\Normative;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * Normative Library: the shared catalog (public laws article by article, Atlantia guides and NIIF summaries) plus
 * any firm-private documents. Search uses PostgreSQL Spanish full-text search; results always carry the document,
 * the article and the reform warning so Luzma cites the source instead of paraphrasing from memory.
 */
class NormativeLibraryService
{
    public const CATEGORY_LABELS = ['fiscal' => 'Fiscal', 'mercantil' => 'Mercantil', 'contable' => 'Contable (NIIF)', 'guia' => 'Guías de estados financieros', 'laboral' => 'Laboral', 'interna' => 'Políticas internas'];

    public const STATUS_LABELS = ['vigente' => 'Vigente', 'vigente_con_reformas' => 'Vigente · con reformas posteriores', 'derogado' => 'Derogado', 'referencia' => 'Resumen de referencia'];

    /**
     * Loads (or refreshes) the shared catalog from database/data/normative. Idempotent: documents are matched by
     * code and their sections replaced, so re-running after a new extraction updates the text.
     *
     * @return array{documents: int, sections: int}
     */
    public function syncCatalog(?string $directory = null): array
    {
        $directory ??= database_path('data/normative');
        $catalog = json_decode((string) file_get_contents($directory.'/catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $documents = 0;
        $sections = 0;
        foreach ($catalog['documents'] as $document) {
            $rows = $document['sections'] ?? null;
            if (isset($document['sections_file'])) {
                $file = $directory.'/'.$document['sections_file'];
                if (! is_file($file)) {
                    throw new RuntimeException('Falta el archivo de artículos '.$document['sections_file']);
                }
                $rows = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['sections'];
            }
            $sections += $this->storeShared($document, $rows ?? []);
            $documents++;
        }

        return ['documents' => $documents, 'sections' => $sections];
    }

    /**
     * Catalog visible to a tenant: the shared library plus its own documents.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(int $organizationId): array
    {
        $counts = DB::table('normative_sections')->groupBy('normative_document_id')->selectRaw('normative_document_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'normative_document_id');

        return array_values($this->visible($organizationId)->orderByRaw("CASE category WHEN 'fiscal' THEN 0 WHEN 'mercantil' THEN 1 WHEN 'guia' THEN 2 WHEN 'contable' THEN 3 ELSE 4 END")->orderBy('title')->get()
            ->map(fn (stdClass $row): array => [...$this->document($row), 'sections_count' => (int) ($counts[$row->id] ?? 0)])->all());
    }

    /**
     * One document with its table of contents and the sections of the selected part (or matching a search).
     *
     * @return array<string, mixed>|null
     */
    public function read(int $organizationId, string $code, ?string $path = null, ?string $search = null, int $limit = 400): ?array
    {
        $row = $this->visible($organizationId)->where('code', $code)->orderByRaw('organization_id IS NULL')->first();
        if ($row === null) {
            return null;
        }
        $parts = DB::table('normative_sections')->where('normative_document_id', $row->id)->whereNotNull('path')->where('path', '!=', '')
            ->selectRaw("split_part(path, ' · ', 1) as part, MIN(position) as first")->groupByRaw("split_part(path, ' · ', 1)")->orderBy('first')->pluck('part')->all();
        $query = DB::table('normative_sections')->where('normative_document_id', $row->id)
            // "Libro I" must not also match "Libro II": the part ends at " ·", " (" or the end.
            ->when($path !== null && $path !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('path', $path)->orWhere('path', 'like', $path.' ·%')->orWhere('path', 'like', $path.' (%')))
            ->when($search !== null && trim($search) !== '', fn ($q) => $q->whereRaw("search @@ websearch_to_tsquery('spanish', ?)", [$search]));

        return [
            'document' => $this->document($row),
            'parts' => array_values(array_map('strval', $parts)),
            'sections' => array_values($query->orderBy('position')->limit($limit)->get()->map(fn (stdClass $section): array => $this->section($section))->all()),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * Full-text search across every visible document, best matches first, with a highlighted fragment.
     *
     * @param  list<string>  $categories
     * @return list<array<string, mixed>>
     */
    public function search(int $organizationId, string $text, int $limit = 8, array $categories = []): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return [];
        }
        $documents = $this->visible($organizationId)->when($categories !== [], fn ($q) => $q->whereIn('category', $categories))->get()->keyBy('id');
        if ($documents->isEmpty()) {
            return [];
        }
        // Any word may match; articles with every word, with the topic in their title, and shorter ones rank first
        // (the length normalization keeps long transitional articles from winning just by size).
        // Word families: long words match by their first letters ("contables" ~ "contabilidad", "obligatorios" ~
        // "obligados"), so the Spanish stemmer's different roots do not hide the right article.
        $words = array_values(array_unique(array_filter(array_map(fn (string $word): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($word)),
            preg_split('/[\s,;:¿?¡!.()"«»\/-]+/u', $text) ?: []), fn (string $word): bool => mb_strlen($word) > 2 && ! in_array($word, ['del', 'las', 'los', 'para', 'por', 'con', 'que', 'una', 'sus', 'or'], true))));
        if ($words === []) {
            return [];
        }
        $prefixes = array_map(fn (string $word): string => mb_strlen($word) >= 7 ? mb_substr($word, 0, 5).':*' : $word, $words);
        $exactAny = implode(' | ', $words);
        $exactAll = implode(' & ', $words);
        $prefixAny = implode(' | ', $prefixes);
        $prefixAll = implode(' & ', $prefixes);
        // Exact (stemmed) matches weigh most; word-family matches only add, so they find the right article without
        // pushing aside the ones that literally use the user's words.
        $rows = DB::table('normative_sections')->whereIn('normative_document_id', $documents->keys()->all())
            ->whereRaw("search @@ to_tsquery('spanish', ?)", [$prefixAny])
            ->select('normative_sections.*')
            ->selectRaw("ts_rank(search, to_tsquery('spanish', ?), 1) + 0.4 * ts_rank(search, to_tsquery('spanish', ?), 1)
                + CASE WHEN search @@ to_tsquery('spanish', ?) THEN 0.5 ELSE 0 END + CASE WHEN search @@ to_tsquery('spanish', ?) THEN 0.25 ELSE 0 END as rank",
                [$exactAny, $prefixAny, $exactAll, $prefixAll])
            ->selectRaw("ts_headline('spanish', content, to_tsquery('spanish', ?), 'MaxWords=45, MinWords=20, StartSel=«, StopSel=», MaxFragments=2') as fragment", [$prefixAny])
            ->orderByDesc('rank')->limit($limit)->get();

        return array_values($rows->map(function (stdClass $row) use ($documents): array {
            $document = $documents[$row->normative_document_id];

            return [...$this->section($row), 'fragment' => (string) $row->fragment, 'rank' => round((float) $row->rank, 4),
                'document_code' => (string) $document->code, 'document' => (string) $document->short_title, 'document_title' => (string) $document->title,
                'status' => (string) $document->status, 'status_label' => self::STATUS_LABELS[$document->status] ?? (string) $document->status,
                'is_full_text' => (bool) $document->is_full_text, 'warning' => $document->warning,
                'destination' => '/library/'.$document->code.'#'.$row->key];
        })->all());
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeShared(array $document, array $rows): int
    {
        return DB::transaction(function () use ($document, $rows): int {
            $values = [
                'title' => $document['title'], 'short_title' => $document['short_title'], 'category' => $document['category'], 'document_type' => $document['document_type'],
                'issuer' => $document['issuer'], 'reference' => $document['reference'], 'status' => $document['status'], 'published_on' => $document['published_on'],
                'effective_on' => $document['effective_on'], 'version' => $document['version'], 'summary' => $document['summary'], 'source_name' => $document['source_name'],
                'source_url' => $document['source_url'], 'official_url' => $document['official_url'], 'is_full_text' => (bool) $document['is_full_text'],
                'license_note' => $document['license_note'], 'warning' => $document['warning'],
                'reforms' => json_encode($document['reforms'] ?? [], JSON_THROW_ON_ERROR),
                'tags' => json_encode(['tags' => $document['tags'] ?? [], 'collections' => $document['collections'] ?? []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ];
            $id = DB::table('normative_documents')->whereNull('organization_id')->where('code', $document['code'])->value('id');
            if ($id === null) {
                $id = DB::table('normative_documents')->insertGetId([...$values, 'public_id' => (string) Str::uuid(), 'code' => $document['code'], 'created_at' => now()]);
            } else {
                DB::table('normative_documents')->where('id', $id)->update($values);
                DB::table('normative_sections')->where('normative_document_id', $id)->delete();
            }
            $reformed = [];
            foreach ($document['reforms'] ?? [] as $reform) {
                foreach ($reform['sections'] ?? [] as $key) {
                    $reformed[$key][] = $reform['decree'].': '.$reform['note'].($reform['effective'] ? ' Vigencia: '.$reform['effective'] : '');
                }
            }
            foreach (array_chunk($rows, 200, true) as $chunk) {
                DB::table('normative_sections')->insert(array_map(fn (array $row, int $position): array => [
                    'normative_document_id' => $id, 'organization_id' => null, 'key' => mb_substr((string) $row['key'], 0, 60), 'label' => mb_substr((string) $row['label'], 0, 120),
                    'heading' => isset($row['heading']) && $row['heading'] !== '' ? mb_substr((string) $row['heading'], 0, 255) : null,
                    'path' => isset($row['path']) ? mb_substr((string) $row['path'], 0, 500) : null, 'content' => (string) $row['content'], 'page' => $row['page'] ?? null,
                    'position' => $position + 1, 'reform_note' => isset($reformed[$row['key']]) ? implode(' ', $reformed[$row['key']]) : null, 'created_at' => now(), 'updated_at' => now(),
                ], $chunk, array_keys($chunk)));
            }

            return count($rows);
        });
    }

    private function visible(int $organizationId): Builder
    {
        return DB::table('normative_documents')->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId));
    }

    /** @return array<string, mixed> */
    private function document(stdClass $row): array
    {
        $meta = (array) json_decode((string) ($row->tags ?? '{}'), true);

        return [
            'id' => (string) $row->public_id, 'code' => (string) $row->code, 'title' => (string) $row->title, 'short_title' => (string) $row->short_title,
            'category' => (string) $row->category, 'category_label' => self::CATEGORY_LABELS[$row->category] ?? (string) $row->category, 'type' => (string) $row->document_type,
            'issuer' => (string) $row->issuer, 'reference' => $row->reference, 'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status,
            'published_on' => $row->published_on, 'effective_on' => $row->effective_on, 'version' => (string) $row->version, 'summary' => (string) $row->summary,
            'source_name' => $row->source_name, 'source_url' => $row->source_url, 'official_url' => $row->official_url, 'is_full_text' => (bool) $row->is_full_text,
            'license_note' => $row->license_note, 'warning' => $row->warning, 'reforms' => (array) json_decode((string) ($row->reforms ?? '[]'), true),
            'tags' => array_values((array) ($meta['tags'] ?? [])), 'collections' => array_values((array) ($meta['collections'] ?? [])), 'private' => $row->organization_id !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function section(stdClass $row): array
    {
        return ['key' => (string) $row->key, 'label' => (string) $row->label, 'heading' => $row->heading, 'path' => $row->path, 'content' => (string) $row->content, 'page' => $row->page === null ? null : (int) $row->page, 'reform_note' => $row->reform_note];
    }
}
