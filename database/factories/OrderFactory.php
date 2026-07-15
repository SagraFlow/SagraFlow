<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\CashRegister;
use App\Models\EventDay;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_day_id' => EventDay::factory(),
            'cash_register_id' => CashRegister::factory(),
            'user_id' => User::factory(),
            'number' => fake()->unique()->numberBetween(1, 1_000_000),
            'service_type' => ServiceType::TableService,
            'status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'total' => 0,
            'paid_at' => now(),
        ];
    }
}
