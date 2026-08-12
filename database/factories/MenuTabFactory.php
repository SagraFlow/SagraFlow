<?php

namespace Database\Factories;

use App\Models\MenuTab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuTab>
 */
class MenuTabFactory extends Factory
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
            'description' => null,
            'columns' => 5,
            'rows' => 4,
        ];
    }

    public function board(int $columns, int $rows): static
    {
        return $this->state(fn () => ['columns' => $columns, 'rows' => $rows]);
    }
}
