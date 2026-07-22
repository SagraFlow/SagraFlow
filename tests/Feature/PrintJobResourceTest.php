<?php

use App\Enums\PaymentMethod;
use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Filament\Resources\PrintJobs\Pages\ListPrintJobs;
use App\Filament\Resources\PrintJobs\PrintJobResource;
use App\Jobs\SendToPrinterJob;
use App\Models\CashRegister;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
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

it('lists print jobs and can filter the failed ones', function () {
    $order = Order::factory()->create();
    $printed = PrintJob::create(['order_id' => $order->id, 'type' => PrintJobType::CustomerReceipt, 'label' => 'Scontrino', 'status' => PrintJobStatus::Printed]);
    $failed = PrintJob::create(['order_id' => $order->id, 'type' => PrintJobType::DepartmentTicket, 'label' => 'Cucina', 'status' => PrintJobStatus::Failed, 'error' => 'Stampante offline']);

    Livewire::test(ListPrintJobs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$printed, $failed])
        ->filterTable('status', PrintJobStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$printed]);
});

it('requeues the order printing from a print job row', function () {
    Queue::fake();

    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);
    $day = EventDay::factory()->create();
    $food = Food::factory()->create();

    $order = Order::place($day, $register, null, null, null, PaymentMethod::Cash, [
        ['food_id' => $food->id, 'food_name' => $food->name, 'unit_price' => 500, 'quantity' => 1, 'note' => null, 'ingredients' => []],
    ]);

    $job = PrintJob::create(['order_id' => $order->id, 'type' => PrintJobType::CustomerReceipt, 'label' => 'Scontrino', 'status' => PrintJobStatus::Failed]);

    Livewire::test(ListPrintJobs::class)
        ->callAction(TestAction::make('reprintOrder')->table($job))
        ->assertHasNoErrors();

    Queue::assertPushed(SendToPrinterJob::class);
});

it('does not allow creating or editing print jobs', function () {
    expect(PrintJobResource::canCreate())->toBeFalse()
        ->and(PrintJobResource::canEdit(new PrintJob))->toBeFalse();
});
