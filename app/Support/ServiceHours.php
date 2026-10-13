<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Opening hours of the platform. The schedule lives in config/service_hours.php and is evaluated in the firm's
 * time zone (Guatemala by default), never in the server's.
 */
final class ServiceHours
{
    public static function enabled(): bool
    {
        return (bool) config('service_hours.enabled');
    }

    public static function isOpen(?CarbonInterface $at = null): bool
    {
        if ((bool) config('service_hours.force_closed')) {
            return false;
        }

        $now = self::now($at);

        return $now->greaterThanOrEqualTo(self::at($now, (string) config('service_hours.opens_at')))
            && $now->lessThan(self::at($now, (string) config('service_hours.closes_at')));
    }

    /** The next moment the platform opens: today if it has not opened yet, otherwise tomorrow. */
    public static function nextOpening(?CarbonInterface $at = null): CarbonImmutable
    {
        $now = self::now($at);
        $today = self::at($now, (string) config('service_hours.opens_at'));

        return $now->lessThan($today) && ! config('service_hours.force_closed') ? $today : $today->addDay();
    }

    /**
     * What the after-hours page shows.
     *
     * @return array{opens_label: string, closes_label: string, next_opening_label: string, next_opening_day: string, next_opening_at: string, timezone_label: string, retry_after: int}
     */
    public static function summary(?CarbonInterface $at = null): array
    {
        $now = self::now($at);
        $next = self::nextOpening($now);
        $seconds = $now->getOffset();
        $minutes = intdiv(abs($seconds) % 3600, 60);
        $offset = ($seconds < 0 ? '-' : '+').intdiv(abs($seconds), 3600).($minutes > 0 ? ':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');

        return [
            'opens_label' => self::at($now, (string) config('service_hours.opens_at'))->format('g:i A'),
            'closes_label' => self::at($now, (string) config('service_hours.closes_at'))->format('g:i A'),
            'next_opening_label' => $next->format('g:i A'),
            'next_opening_day' => $next->isSameDay($now) ? 'hoy' : 'mañana',
            'next_opening_at' => $next->toIso8601String(),
            'timezone_label' => 'Hora de '.self::placeName().' (UTC'.$offset.')',
            'retry_after' => max(60, $next->getTimestamp() - $now->getTimestamp()),
        ];
    }

    private static function now(?CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone((string) config('service_hours.timezone'));
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time.':0'));

        return $day->setTime($hour, $minute);
    }

    private static function placeName(): string
    {
        $zone = (string) config('service_hours.timezone');

        return $zone === 'America/Guatemala' ? 'Guatemala' : str_replace('_', ' ', (string) last(explode('/', $zone)));
    }
}
