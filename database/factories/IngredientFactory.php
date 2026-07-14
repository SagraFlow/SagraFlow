<?php

namespace Database\Factories;

use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
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
            'surcharge' => fake()->randomElement([0, 50, 100, 150]), // centesimi
            'stock' => null,
            'available' => true,
        ];
    }

    public function tracked(int $stock = 100): static
    {
        return $this->state(fn () => ['stock' => $stock]);
    }

    public function unavailable(): static
    {
        return $this->state(fn () => ['available' => false]);
    }

    public function free(): static
    {
        return $this->state(fn () => ['surcharge' => 0]);
    }
}
