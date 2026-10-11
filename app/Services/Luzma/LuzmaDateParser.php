<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use Carbon\CarbonImmutable;

/**
 * Spanish date expressions for reminders ("mañana", "el viernes", "en 2 horas", "15/10 a las 9"), always in the
 * user's company timezone. Returns the moment and the matched phrase so it can be removed from the subject.
 */
class LuzmaDateParser
{
    private const WEEKDAYS = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    /** @return array{at: CarbonImmutable, phrase: string}|null */
    public function parse(string $normalizedText, string $timezone, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now($timezone);
        $text = $normalizedText;
        [$hour, $minute, $timePhrase] = $this->time($text);

        if (preg_match('/\ben (\d+|una?|un par de) (minuto|minutos|hora|horas|dia|dias|semana|semanas)\b/u', $text, $match)) {
            $amount = match ($match[1]) {
                'un', 'una' => 1,
                'un par de' => 2,
                default => (int) $match[1],
            };
            $at = match (true) {
                str_starts_with($match[2], 'minuto') => $now->addMinutes($amount),
                str_starts_with($match[2], 'hora') => $now->addHours($amount),
                str_starts_with($match[2], 'semana') => $this->at($now->addWeeks($amount), $hour, $minute),
                default => $this->at($now->addDays($amount), $hour, $minute),
            };

            return ['at' => $at, 'phrase' => trim($match[0].' '.$timePhrase)];
        }
        if (preg_match('/\bpasado manana\b/u', $text, $match)) {
            return ['at' => $this->at($now->addDays(2), $hour, $minute), 'phrase' => trim($match[0].' '.$timePhrase)];
        }
        if (preg_match('/\bmanana\b/u', $text, $match)) {
            return ['at' => $this->at($now->addDay(), $hour, $minute), 'phrase' => trim($match[0].' '.$timePhrase)];
        }
        if (preg_match('/\b(el |este |proximo )?(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b/u', $text, $match)) {
            $target = self::WEEKDAYS[$match[2]];
            $days = ($target - $now->dayOfWeekIso + 7) % 7;
            $days = $days === 0 ? 7 : $days;

            return ['at' => $this->at($now->addDays($days), $hour, $minute), 'phrase' => trim($match[0].' '.$timePhrase)];
        }
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $text, $match)) {
            $year = isset($match[3]) ? (int) (strlen($match[3]) === 2 ? '20'.$match[3] : $match[3]) : $now->year;
            if (checkdate((int) $match[2], (int) $match[1], $year)) {
                $date = CarbonImmutable::create($year, (int) $match[2], (int) $match[1], 0, 0, 0, $timezone);
                if (! isset($match[3]) && $date->endOfDay()->lt($now)) {
                    $date = $date->addYear();
                }

                return ['at' => $this->at($date, $hour, $minute), 'phrase' => trim($match[0].' '.$timePhrase)];
            }
        }
        if (preg_match('/\b(hoy|esta tarde|en la tarde)\b/u', $text, $match)) {
            $at = $timePhrase !== '' ? $this->at($now, $hour, $minute) : ($match[1] === 'hoy' ? $now->addHour() : $now->setTime(15, 0));

            return ['at' => $at->lt($now) ? $now->addHour() : $at, 'phrase' => trim($match[0].' '.$timePhrase)];
        }
        if ($timePhrase !== '') {
            $at = $this->at($now, $hour, $minute);

            return ['at' => $at->lt($now) ? $at->addDay() : $at, 'phrase' => $timePhrase];
        }

        return null;
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function time(string $text): array
    {
        if (preg_match('/\ba las (\d{1,2})(?::(\d{2}))?\s*(am|pm|de la manana|de la tarde|de la noche)?/u', $text, $match)) {
            $hour = (int) $match[1];
            $suffix = $match[3] ?? '';
            if (in_array($suffix, ['pm', 'de la tarde', 'de la noche'], true) && $hour < 12) {
                $hour += 12;
            } elseif ($suffix === '' && $hour >= 1 && $hour <= 6) {
                $hour += 12;
            }

            return [min($hour, 23), (int) ($match[2] ?? 0), $match[0]];
        }

        return [8, 0, ''];
    }

    private function at(CarbonImmutable $date, int $hour, int $minute): CarbonImmutable
    {
        return $date->setTime($hour, $minute);
    }
}
