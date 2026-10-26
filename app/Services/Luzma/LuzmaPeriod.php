<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use Carbon\CarbonImmutable;

/**
 * The period a question talks about ("este mes", "octubre", "el año pasado", "el primer trimestre", "los últimos 6
 * meses"), with the period it is naturally compared to and the same period one year earlier. Without a period in the
 * words, the current month up to today.
 *
 * @phpstan-type Range array{from: string, to: string, label: string}
 */
final class LuzmaPeriod
{
    public const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @param  'month'|'quarter'|'year'|'range'  $kind
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly string $kind,
        public readonly string $previousFrom,
        public readonly string $previousTo,
        public readonly string $previousLabel,
    ) {}

    public static function fromText(string $text, string $timezone): self
    {
        $text = LuzmaContextService::fold($text);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $year = preg_match('/\b(20\d{2})\b/', $text, $found) === 1 ? (int) $found[1] : null;

        if (preg_match('/\bultim[oa]s? (\d{1,2}|dos|tres|cuatro|seis|doce) meses\b/u', $text, $last) === 1) {
            $count = max(1, min(24, (int) (['dos' => 2, 'tres' => 3, 'cuatro' => 4, 'seis' => 6, 'doce' => 12][$last[1]] ?? $last[1])));
            $from = $today->startOfMonth()->subMonths($count - 1);

            return self::range($from, $today, 'últimos '.$count.' meses');
        }
        if (preg_match('/\b(ano pasado|ano anterior|el ano que paso)\b/u', $text) === 1) {
            return self::year($today->year - 1, $today);
        }
        if ($year !== null && preg_match('/\b(ano|anual|ejercicio)\b/u', $text) === 1 && ! self::monthIn($text)) {
            return self::year($year, $today);
        }
        if (preg_match('/\b(este ano|en el ano|del ano|anual|acumulado|en lo que va del ano|ejercicio)\b/u', $text) === 1) {
            return self::year($today->year, $today);
        }
        if (preg_match('/\b(primer|segundo|tercer|cuarto|1er|2do|3er|4to|q[1-4]|este|ultimo|el) trimestre\b|\btrimestre (pasado|anterior|actual)\b/u', $text, $quarter) === 1) {
            $number = match (true) {
                preg_match('/\b(primer|1er|q1)\b/u', $text) === 1 => 1,
                preg_match('/\b(segundo|2do|q2)\b/u', $text) === 1 => 2,
                preg_match('/\b(tercer|3er|q3)\b/u', $text) === 1 => 3,
                preg_match('/\b(cuarto|4to|q4)\b/u', $text) === 1 => 4,
                preg_match('/\btrimestre (pasado|anterior)\b|\bultimo trimestre\b/u', $text) === 1 => -1,
                default => 0,
            };
            $current = $today->startOfQuarter();
            $start = match (true) {
                $number === -1 => $current->subQuarter(),
                $number === 0 => $current,
                default => CarbonImmutable::create($year ?? $today->year, ($number - 1) * 3 + 1, 1, 0, 0, 0, $timezone),
            };

            return self::quarter($start, $today);
        }
        if (preg_match('/\b(mes pasado|mes anterior|el mes que paso)\b/u', $text) === 1) {
            return self::month($today->startOfMonth()->subMonth(), $today);
        }
        $month = self::monthIn($text);
        if ($month !== null) {
            $start = CarbonImmutable::create($year ?? $today->year, $month, 1, 0, 0, 0, $timezone);
            // "octubre" asked in March means the last October, not a future one.
            if ($year === null && $start->greaterThan($today)) {
                $start = $start->subYear();
            }

            return self::month($start, $today);
        }

        return self::month($today->startOfMonth(), $today);
    }

    /** @return Range */
    public function current(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'label' => $this->label];
    }

    /** @return Range */
    public function previous(): array
    {
        return ['from' => $this->previousFrom, 'to' => $this->previousTo, 'label' => $this->previousLabel];
    }

    /**
     * The same days one year earlier.
     *
     * @return Range
     */
    public function lastYear(): array
    {
        $from = CarbonImmutable::parse($this->from)->subYear();
        $to = CarbonImmutable::parse($this->to)->subYear();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $this->kind === 'month' ? self::monthLabel($from) : $from->format('d/m/Y').' al '.$to->format('d/m/Y')];
    }

    /**
     * Months of the period, oldest first, each one cut at today.
     *
     * @return list<Range>
     */
    public function months(): array
    {
        $months = [];
        $cursor = CarbonImmutable::parse($this->from)->startOfMonth();
        $end = CarbonImmutable::parse($this->to);
        while ($cursor->lessThanOrEqualTo($end)) {
            $last = $cursor->endOfMonth()->startOfDay();
            $months[] = ['from' => $cursor->toDateString(), 'to' => ($last->greaterThan($end) ? $end : $last)->toDateString(), 'label' => self::monthLabel($cursor)];
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    public static function monthLabel(CarbonImmutable $date): string
    {
        return self::MONTHS[$date->month - 1].' '.$date->year;
    }

    private static function monthIn(string $text): ?int
    {
        foreach (self::MONTHS as $index => $name) {
            if (preg_match('/\b'.$name.'\b/u', $text) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    private static function month(CarbonImmutable $start, CarbonImmutable $today): self
    {
        $end = $start->endOfMonth()->startOfDay();
        $to = $end->greaterThan($today) ? $today : $end;
        $previous = $start->subMonth();
        // A month still running is compared with the same days of the previous month.
        $previousTo = $to->equalTo($end) ? $previous->endOfMonth() : $previous->addDays(min($to->day, $previous->daysInMonth) - 1);

        return new self($start->toDateString(), $to->toDateString(), self::monthLabel($start), 'month', $previous->toDateString(), $previousTo->toDateString(), self::monthLabel($previous));
    }

    private static function quarter(CarbonImmutable $start, CarbonImmutable $today): self
    {
        $end = $start->addMonths(3)->subDay();
        $to = $end->greaterThan($today) ? $today : $end;
        $previous = $start->subMonths(3);
        $label = 'trimestre '.(intdiv($start->month - 1, 3) + 1).' de '.$start->year;

        return new self($start->toDateString(), $to->toDateString(), $label, 'quarter', $previous->toDateString(), $previous->addMonths(3)->subDay()->toDateString(),
            'trimestre '.(intdiv($previous->month - 1, 3) + 1).' de '.$previous->year);
    }

    private static function year(int $year, CarbonImmutable $today): self
    {
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $today->getTimezone());
        $end = $start->endOfYear()->startOfDay();
        $to = $end->greaterThan($today) ? $today : $end;
        $previous = $start->subYear();
        // The running year is compared with the same days of the previous year.
        $previousTo = $to->equalTo($end) ? $previous->endOfYear()->startOfDay() : $to->subYear();

        return new self($start->toDateString(), $to->toDateString(), $to->equalTo($end) ? 'año '.$year : 'año '.$year.' hasta el '.$to->format('d/m'), 'year',
            $previous->toDateString(), $previousTo->toDateString(), $to->equalTo($end) ? 'año '.($year - 1) : 'año '.($year - 1).' hasta el '.$previousTo->format('d/m'));
    }

    private static function range(CarbonImmutable $from, CarbonImmutable $to, string $label): self
    {
        $days = (int) $from->diffInDays($to) + 1;

        return new self($from->toDateString(), $to->toDateString(), $label, 'range', $from->subDays($days)->toDateString(), $from->subDay()->toDateString(), 'los '.$days.' días anteriores');
    }
}
