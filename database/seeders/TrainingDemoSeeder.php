<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo courses, enrollments, certifications and training needs for the firm of the demo director. Safe to run twice:
 * it does nothing when the organization already has training data.
 */
class TrainingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = (int) DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('users.email', 'socio.director@atlantia.test')
            ->value('organization_user.organization_id');

        if ($organizationId === 0 || DB::table('training_courses')->where('organization_id', $organizationId)->exists()) {
            return;
        }

        $staff = DB::table('organization_user')->where('organization_id', $organizationId)->where('status', 'active')->orderBy('user_id')->pluck('user_id')->all();
        $now = now();
        $courses = $this->courseCatalog([
            ['Conciliación bancaria avanzada', 'Contabilidad', 'virtual', -9, 'completed'],
            ['Obligaciones fiscales 2026', 'Fiscal', 'virtual', -14, 'completed'],
            ['Gestión de equipos y liderazgo', 'Habilidades blandas', 'presencial', -20, 'completed'],
            ['Actualización fiscal 2026', 'Fiscal', 'virtual', 9, 'open'],
            ['NIIF para PYMES', 'Contabilidad', 'virtual', 14, 'open'],
            ['Nómina en Guatemala', 'Nómina', 'virtual', 29, 'scheduled'],
            ['Excel avanzado para contadores', 'Sistemas', 'virtual', 36, 'scheduled'],
            ['Gestión de riesgos contables', 'Auditoría', 'presencial', 49, 'scheduled'],
        ]);

        foreach ($courses as [$title, $area, $modality, $offset, $status]) {
            $courseId = DB::table('training_courses')->insertGetId([
                'organization_id' => $organizationId,
                'title' => $title,
                'area' => $area,
                'modality' => $modality,
                'starts_on' => $now->copy()->addDays($offset)->toDateString(),
                'capacity' => 20,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (array_slice($staff, 0, $status === 'completed' ? 6 : 4) as $index => $userId) {
                $done = $status === 'completed' || ($status === 'open' && $index === 0 && $offset < 12);
                DB::table('training_enrollments')->insert([
                    'organization_id' => $organizationId,
                    'training_course_id' => $courseId,
                    'user_id' => $userId,
                    'status' => $done ? 'completed' : ($index % 2 === 0 ? 'in_progress' : 'enrolled'),
                    'progress' => $done ? 100 : ($index % 2 === 0 ? 40 + $index * 10 : 0),
                    'satisfaction' => $done ? 86 + ($index * 3) % 12 : null,
                    'completed_on' => $done ? $now->copy()->addDays(min($offset + 1, -1))->toDateString() : null,
                    'created_at' => $now->copy()->subDays(40),
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (array_slice($staff, 0, 4) as $index => $userId) {
            DB::table('training_certifications')->insert([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'name' => ['Contador Público Certificado', 'Auditor interno', 'Especialista en NIIF', 'Gestión tributaria SAT'][$index],
                'issuer' => ['Colegio de Contadores', 'IIA Guatemala', 'IFRS Foundation', 'SAT'][$index],
                'issued_on' => $now->copy()->subMonths(10 + $index * 4)->toDateString(),
                'expires_on' => $now->copy()->addDays([300, 60, -20, 700][$index])->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            ['Fiscal', 'Nuevas disposiciones SAT 2026', 'high', 8, 'planned'],
            ['Contabilidad', 'NIIF completas', 'high', 6, 'pending'],
            ['Sistemas', 'Integraciones bancarias', 'medium', 10, 'planned'],
            ['Auditoría', 'Gestión de riesgos', 'medium', 5, 'pending'],
            ['Habilidades blandas', 'Comunicación con clientes', 'low', 12, 'pending'],
        ] as [$area, $topic, $priority, $employees, $status]) {
            DB::table('training_needs')->insert([
                'organization_id' => $organizationId,
                'area' => $area,
                'topic' => $topic,
                'priority' => $priority,
                'employees' => $employees,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Course rows as title, area, modality, days from today and status.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: string}>  $rows
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: string}>
     */
    private function courseCatalog(array $rows): array
    {
        return $rows;
    }
}
