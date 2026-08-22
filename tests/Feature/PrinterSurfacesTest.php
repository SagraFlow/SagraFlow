<?php

use App\Console\Commands\PollPrinterHealth;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // The health poll has just run: the normal state during service, and the
    // baseline every other assertion here is made against.
    Cache::put(PollPrinterHealth::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
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

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('Cassa 1: offline');
});

it('warns the cashier about a department printer in error', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    // A shared department printer (not assigned to any register), out of paper.
    Printer::factory()->create(['name' => 'Cucina', 'status' => PrinterStatus::PaperOut]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('Cucina: carta esaurita');
});

it('names the register printer first and counts the other broken ones', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    // Register printer named to sort last alphabetically, to prove it still comes first.
    $registerPrinter = Printer::factory()->create(['name' => 'Zeta Cassa', 'status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    Printer::factory()->create(['name' => 'Bar', 'status' => PrinterStatus::Offline]);
    Printer::factory()->create(['name' => 'Cucina', 'status' => PrinterStatus::PaperOut]);

    // The badge has room for one name: the cashier's own printer wins, the rest
    // are a count.
    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('Zeta Cassa: offline +2');
});

it('tells the cashier about a print given up on, loudly', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    // Nothing retries this one: a comanda the kitchen will never get unless
    // somebody asks for it again, and the cashier is the only one standing there.
    jobForPrinter($printer, PrintJobStatus::Failed);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('1 stampa non riuscita')
        ->assertSee('pos-alert-pulse')
        ->call('openIssues')
        ->assertSee('non riuscite');
});

it('tells the cashier when the queue has stopped moving', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    // Queued and still there: with the workers down this is the only trace, and
    // without it the till would go on taking orders that print nowhere.
    $stuck = jobForPrinter($printer, PrintJobStatus::Pending);
    $stuck->update(['queued_at' => now()->subMinute()]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('1 stampa ferma')
        ->assertSee('pos-alert-pulse');
});

it('says nothing about a document queued a moment ago', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    // A second old is what every document looks like on its way out.
    jobForPrinter($printer, PrintJobStatus::Pending)->update(['queued_at' => now()]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertDontSee('ferma')
        ->assertDontSee('pos-alert-pulse');
});

it('says when nobody is watching the printers any more', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    // The schedule has stopped: prints may still be coming out, but nothing
    // recovers on its own any more, and that has to be visible.
    Cache::forget(PollPrinterHealth::HEARTBEAT_KEY);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('monitoraggio fermo')
        ->call('openIssues')
        ->assertSee('Monitoraggio fermo');
});

it('shows no printer badge when everything is ready', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertDontSee('pos-alert-pulse')
        ->assertDontSee('bg-amber-500');
});

it('breathes the badge while a printer cannot print', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('pos-alert-pulse');
});

it('does not breathe for jobs merely waiting to be retried', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);
    jobForPrinter($printer, PrintJobStatus::Held);
    jobForPrinter($printer, PrintJobStatus::Held);

    // Held jobs retry on their own: worth showing, not worth a moving signal.
    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->assertSee('2 in attesa')
        ->assertDontSee('pos-alert-pulse');
});

it('does not warn the cashier about another register printer', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Ready]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    // Another register's printer is offline - not this cashier's concern.
    $otherPrinter = Printer::factory()->create(['name' => 'Cassa 2', 'status' => PrinterStatus::Offline]);
    CashRegister::factory()->create(['printer_id' => $otherPrinter->id]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
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
        ->with('1.2.3.4', 9100, Mockery::type('string'), 1);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('openCashDrawer')
        ->assertNotDispatched('pos-notice');
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
        ->assertDispatched('pos-notice');
});

it('signals a failure when the register has no printer', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $register = CashRegister::factory()->create(['printer_id' => null]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('openCashDrawer')
        ->assertDispatched('pos-notice');
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

it('lists every problematic printer when the badge is tapped', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $registerPrinter = Printer::factory()->create(['name' => 'Zeta Cassa', 'status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    $kitchen = Printer::factory()->create(['name' => 'Cucina', 'status' => PrinterStatus::PaperOut]);
    // Healthy, but sitting on jobs waiting to retry: worth listing too.
    $bar = Printer::factory()->create(['name' => 'Bar', 'status' => PrinterStatus::Ready]);
    jobForPrinter($bar, PrintJobStatus::Held);
    jobForPrinter($bar, PrintJobStatus::Held);
    // Healthy and idle: must not appear.
    Printer::factory()->create(['name' => 'Dolci', 'status' => PrinterStatus::Ready]);

    $component = Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->call('openIssues')
        ->assertSet('showIssues', true)
        ->assertSeeInOrder(['Zeta Cassa', 'Bar', 'Cucina'])   // own printer first, then alphabetical
        ->assertSee('Carta esaurita')
        ->assertSee('2 in attesa')
        ->assertDontSee('Dolci');

    expect($component->get('issues'))->toHaveCount(3);

    $component->call('closeIssues')->assertSet('showIssues', false);
});

it('says all is well when the printers recover while the list is open', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create(['name' => 'Cucina', 'status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);

    $component = Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->call('openIssues')
        ->assertSee('Cucina');

    // The list refreshes on the poll: the modal stays put and reassures.
    $printer->update(['status' => PrinterStatus::Ready]);

    $component->call('openIssues')
        ->assertSet('showIssues', true)
        ->assertSee('Nessun problema in corso');
});

it('ignores another register printer in the issues list', function () {
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa 1', 'status' => PrinterStatus::Offline]);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    $otherPrinter = Printer::factory()->create(['name' => 'Cassa 2', 'status' => PrinterStatus::Offline]);
    CashRegister::factory()->create(['printer_id' => $otherPrinter->id]);

    Livewire::test('pos.printer-badge', ['cashRegisterId' => $register->id])
        ->call('openIssues')
        ->assertSee('Cassa 1')
        ->assertDontSee('Cassa 2');
});
