<?php

use App\Exceptions\PrinterException;
use App\Models\CashRegister;
use App\Models\Printer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids two printers on the same ip and port', function () {
    Printer::factory()->create(['ip_address' => '10.0.0.5', 'port' => 9100]);

    Printer::factory()->create(['ip_address' => '10.0.0.5', 'port' => 9100]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same port on different ips', function () {
    Printer::factory()->create(['ip_address' => '10.0.0.5', 'port' => 9100]);
    Printer::factory()->create(['ip_address' => '10.0.0.6', 'port' => 9100]);

    expect(Printer::count())->toBe(2);
});

it('normalizes the name on write', function () {
    $printer = Printer::factory()->create(['name' => '  Cucina   Griglia ']);

    expect($printer->name)->toBe('Cucina Griglia');
});

it('scopes to active printers', function () {
    Printer::factory()->create(['active' => true]);
    Printer::factory()->create(['active' => false]);

    expect(Printer::active()->count())->toBe(1);
});

it('is the local printer of at most one cash register', function () {
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    expect($printer->cashRegister->is($register))->toBeTrue();
});

it('forbids the same printer on two cash registers', function () {
    $printer = Printer::factory()->create();
    CashRegister::factory()->create(['printer_id' => $printer->id]);

    CashRegister::factory()->create(['printer_id' => $printer->id]);
})->throws(UniqueConstraintViolationException::class);

it('cannot be deleted while linked to a cash register', function () {
    $printer = Printer::factory()->create();
    CashRegister::factory()->create(['printer_id' => $printer->id]);

    expect(fn () => $printer->delete())->toThrow(PrinterException::class);
    expect(Printer::whereKey($printer->id)->exists())->toBeTrue();
});

it('can be deleted when not linked to a cash register', function () {
    $printer = Printer::factory()->create();

    $printer->delete();

    expect(Printer::whereKey($printer->id)->exists())->toBeFalse();
});
