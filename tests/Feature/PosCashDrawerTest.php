<?php

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Printer;
use App\Models\User;
use App\Printing\PrinterConnection;
use App\Printing\PrinterStatusParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    EventDay::factory()->create()->open(auth()->user());
});

/** Records what would have gone down the wire to the register's printer. */
function recordingPrinter(): object
{
    $sent = new stdClass;
    $sent->payloads = [];
    $sent->timeouts = [];

    app()->instance(PrinterConnection::class, new class($sent) extends PrinterConnection
    {
        public function __construct(private readonly stdClass $sent)
        {
            parent::__construct(new PrinterStatusParser);
        }

        public function send(string $host, int $port, string $data, int $timeout = 5): void
        {
            $this->sent->payloads[] = $data;
            $this->sent->timeouts[] = $timeout;
        }
    });

    return $sent;
}

it('never keeps the cashier waiting more than a second for the drawer', function () {
    $sent = recordingPrinter();
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash');

    // The pulse is sent while the cashier waits for the screen, and the printer
    // answers in a millisecond or two: this timeout is only ever paid when it is
    // not answering at all.
    expect($sent->timeouts)->toBe([1]);
});

it('opens the drawer as the cash screen opens, not when the receipt prints', function () {
    $sent = recordingPrinter();
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 500]);

    // The cashier counts the change while the customer is still handing over
    // the money, instead of waiting for a confirmation she has not made yet.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash')
        ->assertSet('showCashModal', true);

    expect($sent->payloads)->toHaveCount(1)
        ->and($sent->payloads[0])->toContain("\x1b\x70"); // ESC p: the drawer pulse
});

it('does not kick the drawer again with the receipt', function () {
    $sent = recordingPrinter();
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 500]);

    // By the time the receipt prints the drawer is usually shut again: a second
    // pulse would pop it open while she is handing the receipt over.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash');

    expect($sent->payloads)->toHaveCount(1);
});

it('says so when the drawer cannot be opened, and takes the payment anyway', function () {
    $register = CashRegister::factory()->create(); // no printer on this station
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash')
        ->assertDispatched('pos-notice')
        ->assertSet('showCashModal', true);
});
