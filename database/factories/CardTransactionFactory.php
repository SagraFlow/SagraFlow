<?php

namespace Database\Factories;

use App\Enums\CardPaymentOutcome;
use App\Enums\CardTransactionStatus;
use App\Models\CardTerminal;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardTransaction>
 */
class CardTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $terminal = CardTerminal::factory();

        return [
            'card_terminal_id' => $terminal,
            'cash_register_id' => CashRegister::factory(),
            'terminal_id' => fake()->numerify('########'),
            'ecr_id' => '00000001',
            'amount_cents' => fake()->numberBetween(100, 5000),
            'status' => CardTransactionStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => CardTransactionStatus::Approved,
            'outcome' => CardPaymentOutcome::Approved,
            'authorization_code' => fake()->bothify('??####'),
            'stan' => fake()->numerify('######'),
            'completed_at' => now(),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn (): array => [
            'status' => CardTransactionStatus::Unknown,
            'error' => 'Il terminale non ha risposto entro il tempo massimo.',
            'completed_at' => now(),
        ]);
    }
}
