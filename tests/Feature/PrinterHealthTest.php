<?php

use App\Console\Commands\PollPrinterHealth;
use App\Enums\PrinterStatus;
use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Jobs\SendToPrinterJob;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Printing\PrinterConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A PrintJob for a printer in a given state, with a frozen render spec.
 */
function heldJob(Printer $printer, PrintJobStatus $status = PrintJobStatus::Held): PrintJob
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

function fakeProbe(PrinterStatus $status): void
{
    test()->mock(PrinterConnection::class, function ($mock) use ($status): void {
        $mock->shouldReceive('probe')->andReturn($status);
    });
}

it('leaves a mark of its own, so a stopped poll is not mistaken for a quiet one', function () {
    Printer::factory()->create();
    fakeProbe(PrinterStatus::Ready);

    Cache::forget(PollPrinterHealth::HEARTBEAT_KEY);

    test()->artisan('printers:poll')->assertSuccessful();

    // Not printers.last_checked_at: a print updates that too, so a busy till
    // would look watched with nothing watching it.
    expect(Cache::get(PollPrinterHealth::HEARTBEAT_KEY))->not->toBeNull();
});

it('records the probed status on each active printer', function () {
    $printer = Printer::factory()->create();
    fakeProbe(PrinterStatus::PaperOut);

    test()->artisan('printers:poll')->assertSuccessful();

    expect($printer->fresh()->status)->toBe(PrinterStatus::PaperOut)
        ->and($printer->fresh()->last_checked_at)->not->toBeNull();
});

it('notifies immediately when a printer is offline', function () {
    $user = User::factory()->create();
    Printer::factory()->create();
    fakeProbe(PrinterStatus::Offline);

    test()->artisan('printers:poll')->assertSuccessful();

    expect($user->notifications()->count())->toBe(1);
});

it('notifies once for a persistently offline printer across polls', function () {
    $user = User::factory()->create();
    Printer::factory()->create();
    fakeProbe(PrinterStatus::Offline);

    test()->artisan('printers:poll')->assertSuccessful();
    test()->artisan('printers:poll')->assertSuccessful();
    test()->artisan('printers:poll')->assertSuccessful();

    expect($user->notifications()->count())->toBe(1);
});

it('re-notifies only after the printer recovers and fails again', function () {
    $user = User::factory()->create();
    Printer::factory()->create();

    fakeProbe(PrinterStatus::Offline);
    test()->artisan('printers:poll')->assertSuccessful();
    expect($user->notifications()->count())->toBe(1);

    // Recovery clears the suppression (re-arms the alert).
    fakeProbe(PrinterStatus::Ready);
    test()->artisan('printers:poll')->assertSuccessful();
    expect($user->notifications()->count())->toBe(1);

    // A fresh outage alerts again.
    fakeProbe(PrinterStatus::Offline);
    test()->artisan('printers:poll')->assertSuccessful();
    expect($user->notifications()->count())->toBe(2);
});

it('notifies for paper-out only after the grace period', function () {
    config(['printing.grace_seconds' => 60]);
    $user = User::factory()->create();
    Printer::factory()->create();
    fakeProbe(PrinterStatus::PaperOut);

    // Just entered paper-out: within grace, no alert.
    test()->artisan('printers:poll')->assertSuccessful();
    expect($user->notifications()->count())->toBe(0);

    // Still out after the grace window: alert.
    test()->travel(61)->seconds();
    test()->artisan('printers:poll')->assertSuccessful();
    expect($user->notifications()->count())->toBe(1);
});

it('releases held jobs in order when the printer recovers', function () {
    Queue::fake();
    $printer = Printer::factory()->create(['status' => PrinterStatus::PaperOut]);
    $first = heldJob($printer);
    $second = heldJob($printer);
    fakeProbe(PrinterStatus::Ready);

    test()->artisan('printers:poll')->assertSuccessful();

    expect($printer->fresh()->status)->toBe(PrinterStatus::Ready);
    // One send for the printer, carrying both documents in order.
    Queue::assertPushed(SendToPrinterJob::class, 1);
    Queue::assertPushed(fn (SendToPrinterJob $job): bool => $job->printJobIds === [$first->id, $second->id]);
});

it('skips a printer that is busy with a send in flight', function () {
    Queue::fake();
    $printer = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    heldJob($printer);
    fakeProbe(PrinterStatus::Offline);

    // The send holds the printer: probing it would steal its socket.
    Cache::lock('printer:'.$printer->id, 30)->get();

    test()->artisan('printers:poll')->assertSuccessful();

    expect($printer->fresh()->status)->toBe(PrinterStatus::Ready)
        ->and($printer->fresh()->last_checked_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('re-dispatches held/stale jobs and skips held jobs for a down printer', function () {
    Queue::fake();

    $ready = Printer::factory()->create(['status' => PrinterStatus::Ready]);
    $held = heldJob($ready);                           // released
    $stale = heldJob($ready, PrintJobStatus::Sending); // stuck mid-send -> reclaimed
    DB::table('print_jobs')->where('id', $stale->id)->update(['updated_at' => now()->subMinutes(5)]);

    $down = Printer::factory()->create(['status' => PrinterStatus::Offline]);
    heldJob($down);                                    // stays held (printer down)

    test()->artisan('print:reconcile')->assertSuccessful();

    // Both documents of the ready printer travel in one send; the down printer
    // gets nothing.
    Queue::assertPushed(SendToPrinterJob::class, 1);
    Queue::assertPushed(fn (SendToPrinterJob $job): bool => $job->printJobIds === [$held->id, $stale->id]);
    expect($stale->fresh()->status)->toBe(PrintJobStatus::Pending);
});
