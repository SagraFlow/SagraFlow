<?php

use App\Exceptions\PrinterException;
use App\Models\CashRegister;
use App\Models\Printer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes the name on write', function () {
    $register = CashRegister::factory()->create(['name' => '  Cassa   1 ']);

    expect($register->name)->toBe('Cassa 1');
});

it('belongs to a local printer', function () {
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    expect($register->printer->is($printer))->toBeTrue();
});

it('prevents deleting the printer it is linked to', function () {
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    expect(fn () => $printer->delete())->toThrow(PrinterException::class);
    expect($register->fresh()->printer_id)->toBe($printer->id);
});
