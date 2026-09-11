<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OperationalRecord;
use App\Models\OperationalRecordEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OperationalRecordEvent> */
class OperationalRecordEventFactory extends Factory
{
    protected $model = OperationalRecordEvent::class;

    public function definition(): array
    {
        return [
            'operational_record_id' => OperationalRecord::factory(),
            'user_id' => User::factory(),
            'action' => 'created',
            'to_status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now(),
        ];
    }
}
