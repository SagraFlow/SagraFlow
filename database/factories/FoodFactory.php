<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Food;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Food>
 */
class FoodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(3, true),
            'price' => fake()->numberBetween(2, 18) * 50, // centesimi, step da 0,50€
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function withIngredients(int $count = 3): static
    {
        return $this->afterCreating(function (Food $food) use ($count) {
            $ingredients = Ingredient::factory($count)->create();
            $food->ingredients()->attach(
                $ingredients->mapWithKeys(fn ($i) => [$i->id => [
                    'quantity' => 1,
                    'min_quantity' => 1,
                    'max_quantity' => 1,
                ]])
            );
        });
    }

    public function withCustomizableIngredients(int $count = 3): static
    {
        return $this->afterCreating(function (Food $food) use ($count) {
            $ingredients = Ingredient::factory($count)->create();
            $food->ingredients()->attach(
                $ingredients->mapWithKeys(fn ($i) => [$i->id => [
                    'quantity' => 1,
                    'min_quantity' => 0,  // rimovibile
                    'max_quantity' => 2,  // raddoppiabile
                ]])
            );
        });
    }
}
