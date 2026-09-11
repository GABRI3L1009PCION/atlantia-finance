<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OperationalRecord;
use App\Services\AccountingService;
use App\Services\Operations\OperationalRecordService;
use App\Services\Operations\OperationalWorkflowRegistry;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationalRecordController extends Controller
{
    public function __construct(
        private WorkspaceContext $workspace,
        private AccountingService $accounting,
        private OperationalWorkflowRegistry $workflows,
        private OperationalRecordService $records,
    ) {}

    public function index(Request $request, string $company, string $module): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $record = $this->accounting->company($organization, $request->user(), $company);
        $definition = $this->workflows->definition($module);
        $this->workspace->requireFeature($organization, $definition['feature']);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = OperationalRecord::query()->where('company_id', $record->id)->where('module', $module)
            ->with('creator:id,name,email')->latest('effective_on')->latest('id');
        $query->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status));
        $query->when($filters['type'] ?? null, fn ($builder, $type) => $builder->where('record_type', $type));
        $query->when($filters['from'] ?? null, fn ($builder, $from) => $builder->whereDate('effective_on', '>=', $from));
        $query->when($filters['to'] ?? null, fn ($builder, $to) => $builder->whereDate('effective_on', '<=', $to));
        $query->when($filters['search'] ?? null, function ($builder, $search): void {
            $builder->where(fn ($nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"));
        });

        return response()->json([
            'module' => $module,
            'workflow' => $definition,
            'records' => $query->paginate((int) ($filters['per_page'] ?? 20)),
        ]);
    }

    public function store(Request $request, string $company, string $module): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $companyRecord = $this->accounting->company($organization, $request->user(), $company, true);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'record_type' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:180'],
            'reference' => ['nullable', 'string', 'max:80'],
            'effective_on' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'data' => ['nullable', 'array'],
        ]);
        $record = $this->records->create($organization, $request->user(), $companyRecord, $module, $data);

        return response()->json(['record' => $record], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $company, string $module, string $record): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $companyRecord = $this->accounting->company($organization, $request->user(), $company);
        $definition = $this->workflows->definition($module);
        $this->workspace->requireFeature($organization, $definition['feature']);
        $operationalRecord = $this->record($companyRecord->id, $module, $record)->load([
            'creator:id,name,email',
            'events' => fn ($query) => $query->with('user:id,name,email')->latest('occurred_at'),
            'files' => fn ($query) => $query->with('uploader:id,name,email')->latest('id'),
        ]);

        return response()->json(['record' => $operationalRecord, 'workflow' => $definition]);
    }

    public function update(Request $request, string $company, string $module, string $record): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $companyRecord = $this->accounting->company($organization, $request->user(), $company, true);
        $operationalRecord = $this->record($companyRecord->id, $module, $record);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'effective_on' => ['sometimes', 'required', 'date'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'amount' => ['sometimes', 'required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'data' => ['sometimes', 'required', 'array'],
        ]);
        $updated = $this->records->update($organization, $request->user(), $companyRecord, $operationalRecord, $data);

        return response()->json(['record' => $updated]);
    }

    public function transition(Request $request, string $company, string $module, string $record): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $companyRecord = $this->accounting->company($organization, $request->user(), $company, true);
        $operationalRecord = $this->record($companyRecord->id, $module, $record);
        $definition = $this->workflows->definition($module);
        $statuses = collect($definition['transitions'])->flatten()->unique()->values()->all();
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'status' => ['required', 'string', Rule::in($statuses)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $updated = $this->records->transition(
            $organization,
            $request->user(),
            $companyRecord,
            $operationalRecord,
            $data['status'],
            $data['request_id'],
            $data['reason'] ?? null,
        );

        return response()->json(['record' => $updated, 'allowed_transitions' => $definition['transitions'][$updated->status] ?? []]);
    }

    private function record(int $companyId, string $module, string $uuid): OperationalRecord
    {
        return OperationalRecord::query()->where('company_id', $companyId)->where('module', $module)
            ->where('public_id', $uuid)->firstOrFail();
    }
}
