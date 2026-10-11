<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CalendarFeedController extends Controller
{
    /** GET calendar/{token}.ics: private calendar of the firm (deadlines, due tasks, period ends) to subscribe from Google Calendar or Outlook. */
    public function show(string $token): Response
    {
        $connection = DB::table('integration_connections')->where('provider', 'calendar')->where('status', 'connected')->where('public_token_hash', hash('sha256', $token))->first();
        abort_if($connection === null, 404);

        $organizationId = (int) $connection->organization_id;
        $organization = DB::table('organizations')->where('id', $organizationId)->first(['name']);
        $stamp = CarbonImmutable::now()->utc()->format('Ymd\THis\Z');
        $events = [];

        $obligations = DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')->where('fiscal_obligations.organization_id', $organizationId)
            ->whereNull('fiscal_obligations.filed_on')->whereNull('fiscal_obligations.completed_at')->whereBetween('fiscal_obligations.due_on', [now()->subDays(30)->toDateString(), now()->addMonths(12)->toDateString()])
            ->orderBy('fiscal_obligations.due_on')->limit(400)->get(['fiscal_obligations.id', 'fiscal_obligations.name', 'fiscal_obligations.due_on', 'companies.name as company']);

        foreach ($obligations as $row) {
            $events[] = $this->allDay('obl-'.$row->id, $row->name.' – '.$row->company, $row->due_on, 'Obligación fiscal', $stamp);
        }

        $tasks = DB::table('work_tasks')->join('companies', 'companies.id', '=', 'work_tasks.company_id')->where('work_tasks.organization_id', $organizationId)->whereNull('work_tasks.deleted_at')
            ->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->whereNotNull('work_tasks.due_at')->whereBetween('work_tasks.due_at', [now()->subDays(30), now()->addMonths(6)])
            ->orderBy('work_tasks.due_at')->limit(400)->get(['work_tasks.id', 'work_tasks.title', 'work_tasks.due_at', 'companies.name as company']);

        foreach ($tasks as $row) {
            $events[] = $this->allDay('task-'.$row->id, $row->title.' – '.$row->company, substr((string) $row->due_at, 0, 10), 'Tarea', $stamp);
        }

        $periods = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->where('accounting_periods.organization_id', $organizationId)
            ->whereNull('accounting_periods.closed_at')->whereBetween('accounting_periods.ends_on', [now()->subDays(30)->toDateString(), now()->addMonths(6)->toDateString()])
            ->orderBy('accounting_periods.ends_on')->limit(200)->get(['accounting_periods.id', 'accounting_periods.name', 'accounting_periods.ends_on', 'companies.name as company']);

        foreach ($periods as $row) {
            $events[] = $this->allDay('period-'.$row->id, 'Cierre '.$row->name.' – '.$row->company, $row->ends_on, 'Fin de período contable', $stamp);
        }

        $body = implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Atlantia Finance//Calendario de la firma//ES', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$this->escape('Atlantia – '.($organization->name ?? 'Firma')), 'X-WR-TIMEZONE:America/Guatemala', ...$events, 'END:VCALENDAR'])."\r\n";

        return response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="atlantia-firma.ics"', 'Cache-Control' => 'private, max-age=900']);
    }

    private function allDay(string $uid, string $summary, string $date, string $kind, string $stamp): string
    {
        $day = CarbonImmutable::parse($date);

        return implode("\r\n", ['BEGIN:VEVENT', 'UID:'.$uid.'@atlantia-finance', 'DTSTAMP:'.$stamp, 'DTSTART;VALUE=DATE:'.$day->format('Ymd'), 'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd'), 'SUMMARY:'.$this->escape($summary), 'DESCRIPTION:'.$this->escape($kind), 'END:VEVENT']);
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }
}
