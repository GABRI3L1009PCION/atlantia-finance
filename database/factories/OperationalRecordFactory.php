<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OperationalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OperationalRecord> */
class OperationalRecordFactory extends Factory
{
    protected $model = OperationalRecord::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'module' => 'proyectos',
            'record_type' => 'project',
            'title' => fake()->sentence(3),
            'status' => 'draft',
            'effective_on' => now()->toDateString(),
            'currency' => 'GTQ',
            'amount_cents' => fake()->numberBetween(0, 1_000_000),
            'data' => [],
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => User::factory(),
        ];
    }
}
