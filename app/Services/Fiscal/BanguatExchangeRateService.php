<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;

/**
 * Official reference exchange rates of Banco de Guatemala (free SOAP service, no key) for the US dollar and the euro,
 * in quetzales per unit. They are stored once for the whole platform; a value outside a sane range is never stored, so
 * a bad answer can not distort sales or purchases. Every company can still record its own rate.
 */
class BanguatExchangeRateService
{
    public const SOURCE = 'BANGUAT';

    private const NAMESPACE = 'http://www.banguat.gob.gt/variables/ws/';

    /** Banguat's code of the US dollar in its catalog of currencies. */
    private const USD_CODE = '2';

    /** Quetzales per unit outside these bounds are treated as a wrong answer. */
    private const SANE_RANGE = ['USD' => ['5', '12'], 'EUR' => ['5', '15']];

    /**
     * Currencies Banguat publishes in US dollars per unit (1 euro = 1.12 dollars), with the bounds of that quote. They are
     * turned into quetzales with the official dollar rate of the same day.
     */
    private const QUOTED_IN_DOLLARS = ['EUR' => ['0.7', '1.8']];

    public function __construct(private TenantDatabaseContext $tenancy) {}

    /**
     * Downloads the rates of every day between both dates and stores the new or corrected ones. A currency that fails is
     * reported and skipped, so one failure never blocks the other currency.
     *
     * @return array{stored: int, currencies: array<string, int|string>}
     */
    public function sync(Carbon $from, Carbon $to): array
    {
        $stored = 0;
        $currencies = [];
        foreach (['USD', 'EUR'] as $currency) {
            try {
                $rates = $this->rates($currency, $from, $to);
                $count = $this->store($currency, $rates);
                $stored += $count;
                $currencies[$currency] = $count;
            } catch (RuntimeException $exception) {
                Log::warning('banguat.sync_failed', ['currency' => $currency, 'error' => $exception->getMessage()]);
                $currencies[$currency] = $exception->getMessage();
            }
        }

        return ['stored' => $stored, 'currencies' => $currencies];
    }

    /**
     * Rates published by Banguat for a currency between two dates, keyed by date (Y-m-d).
     *
     * @return array<string, numeric-string>
     */
    public function rates(string $currency, Carbon $from, Carbon $to): array
    {
        $result = $this->call('TipoCambioRangoMoneda', [
            'fechainit' => $from->format('d/m/Y'),
            'fechafin' => $to->format('d/m/Y'),
            'moneda' => $this->currencyCode($currency),
        ]);

        $dollars = isset(self::QUOTED_IN_DOLLARS[$currency]) ? $this->rates('USD', $from->copy()->subDays(7), $to) : [];
        $rates = [];
        foreach ($result->xpath('//*[local-name()="Var"]') ?: [] as $var) {
            $date = Carbon::createFromFormat('d/m/Y', trim((string) $this->child($var, 'fecha')));
            $value = $this->normalize((string) ($this->child($var, 'venta') ?? $this->child($var, 'referencia') ?? ''));
            if ($date === null || $value === null) {
                continue;
            }
            if (isset(self::QUOTED_IN_DOLLARS[$currency])) {
                $value = $this->inQuetzales($currency, $value, $date, $dollars);
                if ($value === null) {
                    continue;
                }
            }
            [$min, $max] = self::SANE_RANGE[$currency];
            if (bccomp($value, $min, 8) < 0 || bccomp($value, $max, 8) > 0) {
                Log::warning('banguat.rate_out_of_range', ['currency' => $currency, 'date' => $date->toDateString(), 'rate' => $value]);

                continue;
            }
            $rates[$date->toDateString()] = $value;
        }

        if ($rates === []) {
            throw new RuntimeException('Banguat no devolvió tipos de cambio válidos para '.$currency.'.');
        }

        return $rates;
    }

    /**
     * A quote in US dollars per unit, in quetzales: times the official dollar rate of that day (or the last one published
     * before it). Null when the quote is out of bounds or there is no dollar rate to convert it.
     *
     * @param  numeric-string  $dollarsPerUnit
     * @param  array<string, numeric-string>  $dollars
     * @return numeric-string|null
     */
    private function inQuetzales(string $currency, string $dollarsPerUnit, Carbon $date, array $dollars): ?string
    {
        [$min, $max] = self::QUOTED_IN_DOLLARS[$currency];
        if (bccomp($dollarsPerUnit, $min, 8) < 0 || bccomp($dollarsPerUnit, $max, 8) > 0) {
            Log::warning('banguat.rate_out_of_range', ['currency' => $currency, 'date' => $date->toDateString(), 'dollars_per_unit' => $dollarsPerUnit]);

            return null;
        }
        $dollar = null;
        foreach ($dollars as $day => $rate) {
            if ($day <= $date->toDateString() && ($dollar === null || $day > $dollar[0])) {
                $dollar = [$day, $rate];
            }
        }

        return $dollar === null ? null : bcmul($dollarsPerUnit, $dollar[1], 8);
    }

    /** @param array<string, numeric-string> $rates */
    private function store(string $currency, array $rates): int
    {
        return $this->tenancy->runAsSystem(function () use ($currency, $rates): int {
            $changed = 0;
            foreach ($rates as $date => $rate) {
                $current = DB::table('reference_exchange_rates')->where('currency', $currency)->where('quote_currency', 'GTQ')
                    ->where('source', self::SOURCE)->where('effective_on', $date)->value('rate');
                if (is_numeric($current) && bccomp((string) $current, $rate, 8) === 0) {
                    continue;
                }
                DB::table('reference_exchange_rates')->updateOrInsert(
                    ['currency' => $currency, 'quote_currency' => 'GTQ', 'source' => self::SOURCE, 'effective_on' => $date],
                    ['rate' => $rate, 'reference' => (string) config('services.banguat.url'), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                );
                $changed++;
            }

            return $changed;
        });
    }

    private function currencyCode(string $currency): string
    {
        if ($currency === 'USD') {
            return self::USD_CODE;
        }
        $configured = config('services.banguat.eur_code');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Cache::remember('banguat.currency_code.'.$currency, now()->addDays(7), function (): string {
            foreach ($this->call('VariablesDisponibles', [])->xpath('//*[local-name()="Variable"]') ?: [] as $variable) {
                if (preg_match('/\beuros?\b/iu', (string) $this->child($variable, 'descripcion')) === 1) {
                    return trim((string) $this->child($variable, 'moneda'));
                }
            }

            throw new RuntimeException('Banguat no publica el euro en su catálogo de monedas.');
        });
    }

    /** @param array<string, string> $parameters */
    private function call(string $method, array $parameters): SimpleXMLElement
    {
        $body = '';
        foreach ($parameters as $name => $value) {
            $body .= '<'.$name.'>'.htmlspecialchars($value, ENT_XML1).'</'.$name.'>';
        }
        $envelope = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<'.$method.' xmlns="'.self::NAMESPACE.'">'.$body.'</'.$method.'></soap:Body></soap:Envelope>';

        try {
            $response = Http::timeout((int) config('services.banguat.timeout', 20))->retry(2, 2000, throw: false)
                ->withHeaders(['SOAPAction' => '"'.self::NAMESPACE.$method.'"'])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post((string) config('services.banguat.url'));
        } catch (\Throwable $exception) {
            throw new RuntimeException('No fue posible conectar con Banguat: '.$exception->getMessage(), 0, $exception);
        }
        if ($response->failed()) {
            throw new RuntimeException('Banguat respondió con el estado HTTP '.$response->status().'.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new RuntimeException('Banguat devolvió una respuesta que no es XML.');
        }

        return $xml;
    }

    private function child(SimpleXMLElement $node, string $name): ?SimpleXMLElement
    {
        $found = $node->xpath('./*[local-name()="'.$name.'"]');

        return $found === null || $found === false || $found === [] ? null : $found[0];
    }

    /**
     * A decimal with a dot and 8 decimals, or null.
     *
     * @return numeric-string|null
     */
    private function normalize(string $value): ?string
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/^\d{1,6}(\.\d+)?$/', $value) !== 1 || ! is_numeric($value)) {
            return null;
        }

        return bcadd($value, '0', 8);
    }
}
