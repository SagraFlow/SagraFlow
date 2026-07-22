<?php

namespace Database\Seeders;

use App\Enums\PrintDestination;
use App\Enums\ServiceType;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Printer;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Seed a realistic sagra setup: printers, registers, categories with their
     * print routing, ingredients and a small menu.
     */
    public function run(): void
    {
        // Printers (name => model), plain registry with a placeholder network address.
        $printers = collect([
            'Cassa 1' => '192.168.1.101',
            'Cassa 2' => '192.168.1.102',
            'Bar' => '192.168.1.103',
            'Cucina' => '192.168.1.104',
        ])->map(fn (string $ip, string $name): Printer => Printer::create([
            'name' => $name,
            'ip_address' => $ip,
            'port' => 9100,
        ]));

        // Cash registers, each with its own local printer.
        CashRegister::create(['name' => 'Cassa 1', 'printer_id' => $printers['Cassa 1']->id]);
        CashRegister::create(['name' => 'Cassa 2', 'printer_id' => $printers['Cassa 2']->id]);

        // Categories.
        $cucina = Category::create(['name' => 'Cucina', 'position' => 1]);
        $bar = Category::create(['name' => 'Bar', 'position' => 2]);
        $dopoCena = Category::create(['name' => 'Dopo Cena', 'position' => 3]);

        // Operational days (Fri-Sun); Saturday drives the day-restricted dishes below.
        $eventDays = collect([
            '2026-07-10' => 'Venerdì',
            '2026-07-11' => 'Sabato',
            '2026-07-12' => 'Domenica',
        ])->mapWithKeys(fn (string $label, string $date): array => [
            $label => EventDay::create(['date' => $date, 'label' => $label]),
        ]);
        $saturday = $eventDays['Sabato'];

        // Print routing.
        $cucina->printRoutes()->createMany([
            // Table service: kitchen comanda.
            ['service_type' => ServiceType::TableService, 'destination' => PrintDestination::DepartmentPrinter, 'printer_id' => $printers['Cucina']->id, 'grouped' => true, 'position' => 1],
            // Pickup: kitchen comanda + a grouped pickup ticket at the register.
            ['service_type' => ServiceType::Pickup, 'destination' => PrintDestination::CashRegister, 'printer_id' => null, 'grouped' => true, 'position' => 1],
            ['service_type' => ServiceType::Pickup, 'destination' => PrintDestination::DepartmentPrinter, 'printer_id' => $printers['Cucina']->id, 'grouped' => true, 'position' => 2],
        ]);

        $bar->printRoutes()->createMany([
            ['service_type' => ServiceType::TableService, 'destination' => PrintDestination::DepartmentPrinter, 'printer_id' => $printers['Bar']->id, 'grouped' => true, 'position' => 1],
            ['service_type' => ServiceType::Pickup, 'destination' => PrintDestination::CashRegister, 'printer_id' => null, 'grouped' => true, 'position' => 1],
        ]);

        $dopoCena->printRoutes()->createMany([
            // Table service: register ticket + kitchen comanda.
            ['service_type' => ServiceType::TableService, 'destination' => PrintDestination::CashRegister, 'printer_id' => null, 'grouped' => true, 'position' => 1],
            // Pickup: register ticket first, then kitchen comanda.
            ['service_type' => ServiceType::Pickup, 'destination' => PrintDestination::CashRegister, 'printer_id' => null, 'grouped' => true, 'position' => 1],
        ]);

        // Ingredients (name => surcharge in cents).
        $ingredients = collect([
            'Pane' => 0,
            'Salamina' => 200,
            'Polenta Taragna' => 0,
            'Brasato' => 0,
        ])->mapWithKeys(fn (int $surcharge, string $name): array => [
            $name => Ingredient::create(['name' => $name, 'surcharge' => $surcharge]),
        ]);

        // Menu - Cucina.
        Food::create(['category_id' => $cucina->id, 'name' => 'Pane e Salamina', 'price' => 400])
            ->ingredients()->attach([
                $ingredients['Pane']->id => ['quantity' => 1, 'min_quantity' => 0, 'max_quantity' => 1],
                $ingredients['Salamina']->id => ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2],
            ]);

        // Polenta dishes are available on Saturday only.
        $polentaTaragna = Food::create(['category_id' => $cucina->id, 'name' => 'Polenta Taragna', 'price' => 700]);
        $polentaTaragna->ingredients()->attach([
            $ingredients['Polenta Taragna']->id => ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1],
        ]);
        $polentaTaragna->eventDays()->attach($saturday);

        $polentaBrasato = Food::create(['category_id' => $cucina->id, 'name' => 'Brasato e Polenta Taragna', 'price' => 1200]);
        $polentaBrasato->ingredients()->attach([
            $ingredients['Brasato']->id => ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1],
            $ingredients['Polenta Taragna']->id => ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1],
        ]);
        $polentaBrasato->eventDays()->attach($saturday);

        // Menu - Bar & Dopo Cena.
        Food::create(['category_id' => $bar->id, 'name' => 'Birra', 'price' => 500]);
        Food::create(['category_id' => $dopoCena->id, 'name' => 'Caffè', 'price' => 100]);
    }
}
