<?php

namespace Database\Factories;

use App\Models\CardTerminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardTerminal>
 */
class CardTerminalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'ip_address' => fake()->unique()->localIpv4(),
            // Above 1024: the protocol requires it on Linux-based terminals,
            // which is what the SmartPOS family is.
            'port' => 6000,
            'terminal_id' => fake()->unique()->numerify('########'),
            'active' => true,
        ];
    }
}
