<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

/**
 * The effective priority of a task and the reason for it. It is derived from facts (days overdue, legal nature of the
 * work, how close the deadline is), so it is explained to the user and never hidden behind a number.
 */
class TaskPriorityService
{
    public const LABELS = ['low' => 'Baja', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente', 'critical' => 'Crítica'];

    /** @return array{key: string, label: string, reason: string|null} */
    public function assess(string $stored, int $daysOverdue, int $daysToDue, bool $fiscal, bool $closed): array
    {
        $rank = ['low' => 0, 'normal' => 1, 'high' => 2, 'urgent' => 3, 'critical' => 4];
        $key = $stored;
        $reason = null;

        if (! $closed) {
            if ($daysOverdue >= 30 || ($fiscal && $daysOverdue > 0)) {
                [$key, $reason] = ['critical', ($fiscal ? 'Obligación fiscal vencida' : 'Vencida').' hace '.$daysOverdue.' día(s)'];
            } elseif ($daysOverdue > 0) {
                [$key, $reason] = ['urgent', 'Vencida hace '.$daysOverdue.' día(s)'];
            } elseif ($daysToDue <= 3 && ($rank[$stored] ?? 1) < $rank['high']) {
                [$key, $reason] = ['high', $daysToDue <= 0 ? 'Vence hoy' : 'Vence en '.$daysToDue.' día(s)'];
            }
            if (($rank[$key] ?? 1) < ($rank[$stored] ?? 1)) {
                $key = $stored;
                $reason = null;
            }
        }

        return ['key' => $key, 'label' => self::LABELS[$key] ?? $key, 'reason' => $reason];
    }
}
