<?php

namespace Database\Factories;

use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Enums\ServiceType;
use App\Models\Category;
use App\Models\Printer;
use App\Models\PrintRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintRoute>
 */
class PrintRouteFactory extends Factory
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
            'service_type' => ServiceType::TableService,
            'destination' => PrintDestination::DepartmentPrinter,
            'document' => PrintJobType::DepartmentTicket,
            'printer_id' => Printer::factory(),
            'grouped' => true,
        ];
    }

    /**
     * Produce a customer pickup stub (tagliandino) instead of a kitchen comanda.
     */
    public function pickupStub(): static
    {
        return $this->state(fn (): array => ['document' => PrintJobType::PickupStub]);
    }

    /**
     * Print at the ordering cash register's local printer instead of a fixed one.
     */
    public function toCashRegister(): static
    {
        return $this->state(fn (): array => [
            'destination' => PrintDestination::CashRegister,
            'printer_id' => null,
        ]);
    }

    /**
     * Print one ticket per unit instead of grouping the products.
     */
    public function singleTickets(): static
    {
        return $this->state(fn (): array => ['grouped' => false]);
    }
}
