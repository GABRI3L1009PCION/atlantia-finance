<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Progress of an engagement, computed (never typed by hand). Planning weighs 10% (approved plan), the weighted
 * required procedures 70% (completed or not applicable, each by its weight), findings review 10% (no draft findings and
 * no pending working papers once the review started) and the issued report 10%.
 */
class AuditProgressService
{
    private const AFTER_REVIEW = ['findings_review', 'report_draft', 'management_response', 'final_review', 'completed', 'closed'];

    /**
     * @return array{percent: int, planning: bool, procedures_done: int, procedures_total: int, weight_done: int, weight_total: int, review: bool, report: bool}
     */
    public function compute(stdClass $engagement): array
    {
        $planning = DB::table('audit_plans')->where('audit_engagement_id', $engagement->id)->where('status', 'approved')->exists();
        $procedures = DB::table('audit_procedures')->where('audit_engagement_id', $engagement->id)->where('required', true)->get(['status', 'weight']);
        $done = $procedures->whereIn('status', ['completed', 'not_applicable']);
        $weightTotal = (int) $procedures->sum('weight');
        $weightDone = (int) $done->sum('weight');
        $review = in_array($engagement->status, self::AFTER_REVIEW, true)
            && ! DB::table('audit_findings')->where('audit_engagement_id', $engagement->id)->where('status', 'draft')->exists()
            && ! DB::table('audit_working_papers')->where('audit_engagement_id', $engagement->id)->whereNull('superseded_at')->whereIn('status', ['draft', 'prepared', 'in_review'])->exists();
        $report = DB::table('audit_reports')->where('audit_engagement_id', $engagement->id)->where('status', 'issued')->exists();
        $percent = ($planning ? 10 : 0) + ($weightTotal > 0 ? (int) floor(70 * $weightDone / $weightTotal) : 0) + ($review ? 10 : 0) + ($report ? 10 : 0);

        return ['percent' => min(100, $percent), 'planning' => $planning, 'procedures_done' => $done->count(), 'procedures_total' => $procedures->count(),
            'weight_done' => $weightDone, 'weight_total' => $weightTotal, 'review' => $review, 'report' => $report];
    }
}
