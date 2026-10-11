<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Staff training: reads the overview shown to management and records courses, enrollments, certifications and needs. */
class TrainingService
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function __construct(private AccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization): array
    {
        $today = now()->startOfDay();
        $staff = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organization->id)->where('organization_user.status', 'active')
            ->orderBy('users.name')->get(['users.id', 'users.name']);
        $courses = DB::table('training_courses')->where('organization_id', $organization->id)->orderBy('starts_on')->get();
        $enrollments = DB::table('training_enrollments')->where('organization_id', $organization->id)->get();
        $certifications = DB::table('training_certifications')->join('users', 'users.id', '=', 'training_certifications.user_id')
            ->where('training_certifications.organization_id', $organization->id)->orderBy('training_certifications.expires_on')
            ->get(['training_certifications.*', 'users.name as user_name']);
        $needs = DB::table('training_needs')->where('organization_id', $organization->id)->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderByDesc('id')->get();

        $byCourse = $enrollments->groupBy('training_course_id');
        $finished = $enrollments->where('status', 'completed');
        $rated = $finished->whereNotNull('satisfaction');
        $state = fn (object $cert): string => $cert->expires_on === null || $cert->expires_on > $today->copy()->addDays(90)->toDateString()
            ? 'valid'
            : ($cert->expires_on >= $today->toDateString() ? 'expiring' : 'expired');

        $months = collect(range(5, 0))->map(fn (int $back) => now()->startOfMonth()->subMonths($back));
        $trend = $months->map(function (CarbonInterface $month) use ($enrollments): array {
            $end = $month->copy()->endOfMonth();
            $existing = $enrollments->filter(fn (object $row): bool => Carbon::parse($row->created_at)->lte($end));
            $done = $existing->filter(fn (object $row): bool => $row->completed_on !== null && $row->completed_on <= $end->toDateString());

            return ['label' => self::MONTHS[$month->month - 1], 'value' => $existing->isEmpty() ? 0 : (int) round($done->count() / $existing->count() * 100)];
        })->values();

        return [
            'summary' => [
                'staff' => $staff->count(),
                'courses_completed' => $courses->where('status', 'completed')->count(),
                'certifications' => $certifications->filter(fn (object $cert): bool => $state($cert) !== 'expired')->count(),
                'progress' => $enrollments->isEmpty() ? 0 : (int) round($enrollments->avg('progress')),
                'satisfaction' => $rated->isEmpty() ? null : (int) round($rated->avg('satisfaction')),
            ],
            'trend' => $trend->all(),
            'areas' => $courses->groupBy('area')->map(fn ($group, string $area): array => ['name' => $area, 'value' => $group->count()])->sortByDesc('value')->values()->all(),
            'certification_status' => [
                'valid' => $certifications->filter(fn (object $cert): bool => $state($cert) === 'valid')->count(),
                'expiring' => $certifications->filter(fn (object $cert): bool => $state($cert) === 'expiring')->count(),
                'expired' => $certifications->filter(fn (object $cert): bool => $state($cert) === 'expired')->count(),
            ],
            'courses' => $courses->map(fn (object $course): array => [
                'id' => (int) $course->id,
                'title' => $course->title,
                'area' => $course->area,
                'modality' => $course->modality,
                'starts_on' => $course->starts_on,
                'capacity' => $course->capacity,
                'status' => $course->status,
                'enrolled' => ($byCourse->get($course->id) ?? collect())->count(),
                'completed' => ($byCourse->get($course->id) ?? collect())->where('status', 'completed')->count(),
                'satisfaction' => ($rate = ($byCourse->get($course->id) ?? collect())->whereNotNull('satisfaction'))->isEmpty() ? null : (int) round($rate->avg('satisfaction')),
                'last_completion' => ($byCourse->get($course->id) ?? collect())->max('completed_on'),
            ])->values()->all(),
            'certifications' => $certifications->map(fn (object $cert): array => [
                'id' => (int) $cert->id,
                'name' => $cert->name,
                'issuer' => $cert->issuer,
                'user' => $cert->user_name,
                'issued_on' => $cert->issued_on,
                'expires_on' => $cert->expires_on,
                'state' => $state($cert),
            ])->values()->all(),
            'employees' => $staff->map(function (object $member) use ($enrollments): array {
                $mine = $enrollments->where('user_id', $member->id);

                return [
                    'id' => (int) $member->id,
                    'name' => $member->name,
                    'assigned' => $mine->count(),
                    'completed' => $mine->where('status', 'completed')->count(),
                    'progress' => $mine->isEmpty() ? 0 : (int) round($mine->avg('progress')),
                ];
            })->filter(fn (array $row): bool => $row['assigned'] > 0)->values()->all(),
            'needs' => $needs->map(fn (object $need): array => [
                'id' => (int) $need->id,
                'area' => $need->area,
                'topic' => $need->topic,
                'priority' => $need->priority,
                'employees' => (int) $need->employees,
                'status' => $need->status,
            ])->all(),
            'staff' => $staff->map(fn (object $member): array => ['id' => (int) $member->id, 'name' => $member->name])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function createCourse(Organization $organization, User $actor, array $data): int
    {
        $id = (int) DB::table('training_courses')->insertGetId([
            'organization_id' => $organization->id,
            'title' => $data['title'],
            'area' => $data['area'],
            'modality' => $data['modality'],
            'starts_on' => $data['starts_on'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'status' => $data['status'] ?? 'scheduled',
            'description' => $data['description'] ?? null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'training.course_created', 'training_courses', $id, ['title' => $data['title']]);

        return $id;
    }

    /** @param list<int> $userIds */
    public function enroll(Organization $organization, User $actor, int $courseId, array $userIds): int
    {
        $course = DB::table('training_courses')->where('organization_id', $organization->id)->where('id', $courseId)->firstOrFail();
        $valid = DB::table('organization_user')->where('organization_id', $organization->id)->where('status', 'active')->whereIn('user_id', $userIds)->pluck('user_id');
        $added = 0;

        foreach ($valid as $userId) {
            $added += DB::table('training_enrollments')->insertOrIgnore([
                'organization_id' => $organization->id,
                'training_course_id' => $course->id,
                'user_id' => $userId,
                'status' => 'enrolled',
                'progress' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->accounting->audit($organization, $actor, 'training.enrolled', 'training_courses', (int) $course->id, ['added' => $added]);

        return $added;
    }

    public function updateProgress(Organization $organization, User $actor, int $enrollmentId, int $progress, ?int $satisfaction): void
    {
        $enrollment = DB::table('training_enrollments')->where('organization_id', $organization->id)->where('id', $enrollmentId)->firstOrFail();
        $completed = $progress >= 100;

        DB::table('training_enrollments')->where('id', $enrollment->id)->update([
            'progress' => min(100, $progress),
            'status' => $completed ? 'completed' : ($progress > 0 ? 'in_progress' : 'enrolled'),
            'satisfaction' => $completed ? $satisfaction : null,
            'completed_on' => $completed ? ($enrollment->completed_on ?? now()->toDateString()) : null,
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'training.progress_updated', 'training_enrollments', $enrollmentId, ['progress' => $progress]);
    }

    /** @param array<string, mixed> $data */
    public function createCertification(Organization $organization, User $actor, array $data): int
    {
        $id = (int) DB::table('training_certifications')->insertGetId([
            'organization_id' => $organization->id,
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'issuer' => $data['issuer'] ?? null,
            'issued_on' => $data['issued_on'],
            'expires_on' => $data['expires_on'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'training.certification_created', 'training_certifications', $id, ['name' => $data['name']]);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function createNeed(Organization $organization, User $actor, array $data): int
    {
        $id = (int) DB::table('training_needs')->insertGetId([
            'organization_id' => $organization->id,
            'area' => $data['area'],
            'topic' => $data['topic'],
            'priority' => $data['priority'],
            'employees' => $data['employees'] ?? 0,
            'status' => 'pending',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'training.need_created', 'training_needs', $id, ['topic' => $data['topic']]);

        return $id;
    }

    public function updateNeed(Organization $organization, User $actor, int $needId, string $status): void
    {
        $updated = DB::table('training_needs')->where('organization_id', $organization->id)->where('id', $needId)->update(['status' => $status, 'updated_at' => now()]);
        abort_if($updated === 0, 404);
        $this->accounting->audit($organization, $actor, 'training.need_updated', 'training_needs', $needId, ['status' => $status]);
    }
}
