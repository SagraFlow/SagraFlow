<?php

namespace Database\Factories;

use App\Models\EventDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventDay>
 */
class EventDayFactory extends Factory
{
    /**
     * Monotonic offset so each generated day gets a distinct date without
     * relying on Faker's unique(), which does not dedupe across rows.
     */
    protected static int $dayOffset = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->startOfYear()->addDays(static::$dayOffset++)->format('Y-m-d'),
            'label' => null,
            'opened_at' => null,
            'closed_at' => null,
            'opened_by' => null,
            'closed_by' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'opened_at' => now(),
            'opened_by' => User::factory(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'opened_at' => now()->subHours(6),
            'closed_at' => now(),
            'opened_by' => User::factory(),
            'closed_by' => User::factory(),
        ]);
    }
}
