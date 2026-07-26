<?php

use App\Enums\PrinterStatus;
use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Exceptions\PrinterException;
use App\Filament\Resources\Printers\Pages\ManagePrinters;
use App\Filament\Resources\PrintJobs\Pages\ListPrintJobs;
use App\Jobs\SendToPrinterJob;
use App\Models\CashRegister;
use App\Models\EventDay;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Printing\PrinterConnection;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function jobForPrinter(Printer $printer, PrintJobStatus $status): PrintJob
{
    return PrintJob::create([
        'order_id' => Order::factory()->create()->id,
        'printer_id' => $printer->id,
        'printer_name' => $printer->name,
        'type' => PrintJobType::DepartmentTicket,
        'label' => 'Comanda',
        'status' => $status,
        'spec' => ['items' => [['name' => 'Panino', 'quantity' => 1, 'deviation' => '', 'note' => null]]],
    ]);
}

it('warns the cashier when the register printer is offline', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Offline');
});

it('warns the cashier about a department printer in error', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    // A shared department printer (not assigned to any register), out of paper.
    Printer::factory()->create(['name' => 'Cucina', 'status' => PrinterStatus::PaperOut]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Cucina');
});

it('lists the register printer first, then the others alphabetically', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    // Register printer named to sort last alphabetically, to prove it still comes first.
    $registerPrinter = Printer::factory()->create(['name' => 'Zeta Cassa', 'status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    Printer::factory()->create(['name' => 'Bar', 'status' => PrinterStatus::Offline]);
    Printer::factory()->create(['name' => 'Cucina', 'status' => PrinterStatus::PaperOut]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSeeInOrder(['Zeta Cassa', 'Bar', 'Cucina']);
});

it('does not warn the cashier about another register printer', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    // Another register's printer is offline - not this cashier's concern.
    $otherPrinter = Printer::factory()->create(['name' => 'Cassa 2', 'status' => PrinterStatus::Offline]);
    CashRegister::factory()->create(['printer_id' => $otherPrinter->id]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertDontSee('Cassa 2');
});

it('kicks the cash drawer on the register printer', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['ip_address' => '1.2.3.4', 'port' => 9100]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    $this->mock(PrinterConnection::class)
        ->shouldReceive('send')
        ->once()
        ->with('1.2.3.4', 9100, Mockery::type('string'), 3);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('openCashDrawer')
        ->assertNotDispatched('drawer-failed');
});

it('signals a failure when the drawer cannot be kicked', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    $this->mock(PrinterConnection::class)
        ->shouldReceive('send')
        ->andThrow(new PrinterException('offline'));

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('openCashDrawer')
        ->assertDispatched('drawer-failed');
});

it('signals a failure when the register has no printer', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $register = CashRegister::factory()->create(['printer_id' => null]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('openCashDrawer')
        ->assertDispatched('drawer-failed');
});

it('configures offline-status monitoring only when not already enabled', function () {
    $printer = Printer::factory()->create();

    // Already configured -> must NOT rewrite the non-volatile memory switch.
    $this->mock(PrinterConnection::class, function ($mock): void {
        $mock->shouldReceive('offlineStatusEnabled')->once()->andReturn(true);
        $mock->shouldReceive('enableOfflineStatus')->never();
    });

    Livewire::test(ManagePrinters::class)
        ->callAction(TestAction::make('configureMonitoring')->table($printer));
});

it('enables offline-status monitoring when the printer is not configured', function () {
    $printer = Printer::factory()->create();

    $this->mock(PrinterConnection::class, function ($mock): void {
        $mock->shouldReceive('offlineStatusEnabled')->once()->andReturn(false);
        $mock->shouldReceive('enableOfflineStatus')->once();
    });

    Livewire::test(ManagePrinters::class)
        ->callAction(TestAction::make('configureMonitoring')->table($printer));
});

it('verifies the offline-status configuration', function () {
    $printer = Printer::factory()->create();

    $this->mock(PrinterConnection::class, function ($mock): void {
        $mock->shouldReceive('offlineStatusEnabled')->once()->andReturn(true);
    });

    Livewire::test(ManagePrinters::class)
        ->callAction(TestAction::make('verifyMonitoring')->table($printer))
        ->assertHasNoErrors();
});

it('queues a test print from the printers table', function () {
    Queue::fake();
    $printer = Printer::factory()->create();

    Livewire::test(ManagePrinters::class)
        ->callAction(TestAction::make('testPrint')->table($printer));

    Queue::assertPushed(SendToPrinterJob::class, 1);
    expect(PrintJob::where('printer_id', $printer->id)->where('label', 'Test di stampa')->count())->toBe(1);
});

it('releases held jobs from the printers table', function () {
    Queue::fake();
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    jobForPrinter($printer, PrintJobStatus::Held);

    Livewire::test(ManagePrinters::class)
        ->callAction(TestAction::make('release')->table($printer));

    Queue::assertPushed(SendToPrinterJob::class, 1);
});

it('cancels a not-yet-printed job from the print jobs table', function () {
    $printer = Printer::factory()->create();
    $job = jobForPrinter($printer, PrintJobStatus::Pending);

    Livewire::test(ListPrintJobs::class)
        ->callAction(TestAction::make('cancel')->table($job));

    expect($job->fresh()->status)->toBe(PrintJobStatus::Cancelled);
});

it('reprints a single job from the print jobs table', function () {
    Queue::fake();
    $printer = Printer::factory()->create();
    $job = jobForPrinter($printer, PrintJobStatus::Failed);

    Livewire::test(ListPrintJobs::class)
        ->callAction(TestAction::make('reprint')->table($job));

    Queue::assertPushed(SendToPrinterJob::class, 1);
    expect($job->fresh()->status)->toBe(PrintJobStatus::Pending);
});
