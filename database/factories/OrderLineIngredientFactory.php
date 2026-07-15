<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\OrderLine;
use App\Models\OrderLineIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLineIngredient>
 */
class OrderLineIngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'ingredient_id' => Ingredient::factory(),
            'ingredient_name' => fake()->word(),
            'quantity' => 1,
            'base_quantity' => 1,
            'surcharge' => 0,
        ];
    }
}
