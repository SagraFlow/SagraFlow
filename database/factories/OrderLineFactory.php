<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(100, 2000);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'food_id' => Food::factory(),
            'food_name' => fake()->words(2, true),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $unitPrice * $quantity,
        ];
    }
}
