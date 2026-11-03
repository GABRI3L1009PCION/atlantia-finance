<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/** A month as Guatemalan documents name it ("octubre 2026"), independent of the application locale. */
final class SpanishMonth
{
    private const NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public static function label(CarbonInterface $date): string
    {
        return self::NAMES[$date->month - 1].' '.$date->year;
    }
}
