<?php

use App\Enums\PaymentMethod;
use App\Enums\PrintDestination;
use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Enums\ServiceType;
use App\Exceptions\PrinterException;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Jobs\SendToPrinterJob;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintRoute;
use App\Models\User;
use App\Printing\Documents\CustomerReceipt;
use App\Printing\Documents\DepartmentTicket;
use App\Printing\Documents\PickupStub;
use App\Printing\OrderPrinter;
use App\Printing\OrderPrintRouter;
use App\Printing\PrinterConnection;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A placed table order (Panino x2) whose category routes a department comanda.
 *
 * @return array{order: Order, registerPrinter: Printer, departmentPrinter: Printer}
 */
function orderWithRoute(bool $grouped = true, PrintDestination $destination = PrintDestination::DepartmentPrinter): array
{
    $day = EventDay::factory()->create();
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa Bar']);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    $departmentPrinter = Printer::factory()->create(['name' => 'Cucina']);
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Panino', 'price' => 500]);

    PrintRoute::factory()->for($category)->create([
        'service_type' => ServiceType::TableService,
        'destination' => $destination,
        'printer_id' => $destination === PrintDestination::DepartmentPrinter ? $departmentPrinter->id : null,
        'grouped' => $grouped,
    ]);

    $order = Order::place($day, $register, null, 5, null, PaymentMethod::Cash, [
        ['food_id' => $food->id, 'food_name' => 'Panino', 'unit_price' => 500, 'quantity' => 2, 'note' => null, 'ingredients' => []],
    ]);

    return ['order' => $order, 'registerPrinter' => $registerPrinter, 'departmentPrinter' => $departmentPrinter];
}

it('renders a customer receipt with prices, total and payment', function () {
    ['order' => $order] = orderWithRoute();

    $data = (new CustomerReceipt($order->load('lines'), 'Sagra Test', true))->render();

    expect($data)
        ->toContain('Sagra Test')
        ->toContain('#'.$order->number)
        ->toContain('Panino')
        ->toContain('TOTALE')
        ->toContain('Contanti');
});

it('prints the configured logo on the receipt', function () {
    ['order' => $order] = orderWithRoute();
    $order->load('lines');

    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    $image = imagecreatetruecolor(120, 60);
    imagefilledrectangle($image, 0, 0, 119, 59, imagecolorallocate($image, 0, 0, 0));
    imagepng($image, $path);
    imagedestroy($image);

    $withLogo = (new CustomerReceipt($order, 'Sagra Test', false, $path))->render();
    $withoutLogo = (new CustomerReceipt($order, 'Sagra Test', false, null))->render();

    expect(strlen($withLogo))->toBeGreaterThan(strlen($withoutLogo));

    @unlink($path);
});

it('shows the amount received and change on a cash receipt', function () {
    $day = EventDay::factory()->create();
    $food = Food::factory()->create(['name' => 'Panino', 'price' => 500]);

    $order = Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        ['food_id' => $food->id, 'food_name' => 'Panino', 'unit_price' => 500, 'quantity' => 2, 'note' => null, 'ingredients' => []],
    ], cashReceived: 2000);

    expect($order->total)->toBe(1000)
        ->and($order->changeGiven())->toBe(1000);

    $data = (new CustomerReceipt($order->load('lines'), 'Sagra', false))->render();

    expect($data)->toContain('Ricevuto')->toContain('Resto');
});

it('ignores a missing logo without breaking the receipt', function () {
    ['order' => $order] = orderWithRoute();
    $order->load('lines');

    $data = (new CustomerReceipt($order, 'Sagra Test', false, '/does/not/exist.png'))->render();

    expect($data)->toContain('Sagra Test');
});

it('renders a department ticket without prices', function () {
    ['order' => $order] = orderWithRoute();

    $data = (new DepartmentTicket($order, [
        ['name' => 'Panino', 'quantity' => 2, 'deviation' => '', 'note' => null],
    ]))->render();

    expect($data)
        ->toContain('#'.$order->number)
        ->toContain('Tavolo')
        ->toContain((string) $order->table_number)
        ->toContain('2x Panino')
        ->not->toContain('5,00');
});

/**
 * A placed table order with covers, a normal category route and a covers route.
 *
 * @return array{order: Order, categoryPrinter: Printer, coversPrinter: Printer}
 */
function orderWithCoversRoute(int $covers = 4, PrintDestination $destination = PrintDestination::DepartmentPrinter): array
{
    $day = EventDay::factory()->create();
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa']);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    $categoryPrinter = Printer::factory()->create(['name' => 'Cucina']);
    $coversPrinter = Printer::factory()->create(['name' => 'Reparto Coperti']);
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Panino', 'price' => 500]);

    PrintRoute::factory()->for($category)->create([
        'service_type' => ServiceType::TableService,
        'destination' => PrintDestination::DepartmentPrinter,
        'printer_id' => $categoryPrinter->id,
        'grouped' => true,
    ]);
    PrintRoute::factory()->forCovers()->create([
        'service_type' => ServiceType::TableService,
        'destination' => $destination,
        'printer_id' => $destination === PrintDestination::DepartmentPrinter ? $coversPrinter->id : null,
        'grouped' => true,
    ]);

    $order = Order::place($day, $register, null, 5, null, PaymentMethod::Cash, [
        ['food_id' => $food->id, 'food_name' => 'Panino', 'unit_price' => 500, 'quantity' => 1, 'note' => null, 'ingredients' => []],
    ], covers: $covers, coverCharge: 200);

    return ['order' => $order, 'categoryPrinter' => $categoryPrinter, 'coversPrinter' => $coversPrinter];
}

it('routes a covers ticket to its configured printer, listing the covers count', function () {
    ['order' => $order, 'coversPrinter' => $coversPrinter] = orderWithCoversRoute(covers: 4);

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $coversTask = collect(app(OrderPrintRouter::class)->tasks($order))
        ->first(fn ($task): bool => $task->label === 'Coperti');

    expect($coversTask)->not->toBeNull()
        ->and($coversTask->type)->toBe(PrintJobType::DepartmentTicket)
        ->and($coversTask->printer->id)->toBe($coversPrinter->id)
        ->and($coversTask->document->render())->toContain('4x Coperti');
});

it('resolves a covers route to the register printer for a cash-register destination', function () {
    ['order' => $order] = orderWithCoversRoute(destination: PrintDestination::CashRegister);

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $coversTask = collect(app(OrderPrintRouter::class)->tasks($order))
        ->first(fn ($task): bool => $task->label === 'Coperti');

    expect($coversTask->printer->name)->toBe('Cassa');
});

it('prints no covers ticket when the order has no covers', function () {
    ['order' => $order] = orderWithCoversRoute(covers: 0);

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $coversTasks = collect(app(OrderPrintRouter::class)->tasks($order))
        ->filter(fn ($task): bool => $task->label === 'Coperti');

    expect($coversTasks)->toBeEmpty();
});

it('omits the covers line from a product comanda header', function () {
    $order = Order::factory()->create(['covers' => 4, 'table_number' => 12, 'number' => 7]);

    $data = (new DepartmentTicket($order, [
        ['name' => 'Panino', 'quantity' => 2, 'deviation' => '', 'note' => null],
    ]))->render();

    expect($data)->not->toContain('Coperti');
});

it('queues a dedicated print job for the covers ticket', function () {
    Queue::fake();
    ['order' => $order] = orderWithCoversRoute(covers: 4);

    app(OrderPrinter::class)->print($order);

    // Receipt + product comanda + covers comanda.
    Queue::assertPushed(SendToPrinterJob::class, 3);
    expect(PrintJob::where('order_id', $order->id)->where('label', 'Coperti')->count())->toBe(1);
});

it('renders a pickup stub with the event name, details and products, no prices', function () {
    ['order' => $order] = orderWithRoute();

    $data = (new PickupStub($order, 'Sagra Test', 'Bar', [
        ['name' => 'Birra', 'quantity' => 2, 'deviation' => '', 'note' => null],
    ]))->render();

    expect($data)
        ->toContain('Sagra Test')
        ->toContain('N. Ordine')
        ->toContain('Bar')
        ->toContain('#'.$order->number)
        ->toContain('2x Birra')
        ->not->toContain('5,00');
});

it('starts a table-less comanda straight at the details, with no leading feed', function () {
    $order = Order::factory()->create(['table_number' => null, 'number' => 7]);

    $data = (new DepartmentTicket($order, [
        ['name' => 'Panino', 'quantity' => 1, 'deviation' => '', 'note' => null],
    ]))->render();
    $header = substr($data, 0, strpos($data, 'N. Ordine'));

    expect($header)->not->toContain("\n")->not->toContain("\x1bd");
});

it('does not start the receipt with a blank gap when it has no header', function () {
    ['order' => $order] = orderWithRoute();
    $order->load('lines');

    // Empty event name and no logo: nothing is printed above the details.
    $data = (new CustomerReceipt($order, '', false, null))->render();
    $header = substr($data, 0, strpos($data, 'N. Ordine'));

    expect($header)->not->toContain("\x1bd\x02");
});

it('separates the receipt header from the details when it has an event name', function () {
    ['order' => $order] = orderWithRoute();
    $order->load('lines');

    $data = (new CustomerReceipt($order, 'Sagra Test', false, null))->render();
    $header = substr($data, 0, strpos($data, 'N. Ordine'));

    expect($header)->toContain('Sagra Test')->toContain("\x1bd\x02");
});

it('does not start the pickup stub with a blank gap when it has no event name', function () {
    ['order' => $order] = orderWithRoute();

    $data = (new PickupStub($order, '', 'Bar', [
        ['name' => 'Birra', 'quantity' => 2, 'deviation' => '', 'note' => null],
    ]))->render();
    $header = substr($data, 0, strpos($data, 'N. Ordine'));

    expect($header)->not->toContain("\x1bd\x02");
});

it('routes a pickup stub to the register for a pickup order', function () {
    $day = EventDay::factory()->create();
    $registerPrinter = Printer::factory()->create(['name' => 'Cassa 1']);
    $register = CashRegister::factory()->create(['printer_id' => $registerPrinter->id]);
    $category = Category::factory()->create(['name' => 'Bar']);
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Birra', 'price' => 500]);

    PrintRoute::factory()->for($category)->toCashRegister()->pickupStub()->create([
        'service_type' => ServiceType::Pickup,
        'grouped' => true,
    ]);

    $order = Order::place($day, $register, null, null, null, PaymentMethod::Cash, [
        ['food_id' => $food->id, 'food_name' => 'Birra', 'unit_price' => 500, 'quantity' => 2, 'note' => null, 'ingredients' => []],
    ]);

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $stub = collect(app(OrderPrintRouter::class)->tasks($order))
        ->first(fn ($task): bool => $task->type === PrintJobType::PickupStub);

    expect($stub)->not->toBeNull()
        ->and($stub->printer->id)->toBe($registerPrinter->id);
});

it('routes a receipt to the register and a grouped comanda to the department', function () {
    ['order' => $order, 'registerPrinter' => $register, 'departmentPrinter' => $department] = orderWithRoute(grouped: true);

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $tasks = app(OrderPrintRouter::class)->tasks($order);

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->type)->toBe(PrintJobType::CustomerReceipt)
        ->and($tasks[0]->printer->id)->toBe($register->id)
        ->and($tasks[1]->type)->toBe(PrintJobType::DepartmentTicket)
        ->and($tasks[1]->printer->id)->toBe($department->id);
});

it('prints one comanda per portion when the route is not grouped', function () {
    ['order' => $order] = orderWithRoute(grouped: false); // quantity 2

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $tasks = app(OrderPrintRouter::class)->tasks($order);

    $comande = array_filter($tasks, fn ($task): bool => $task->type === PrintJobType::DepartmentTicket);

    expect($comande)->toHaveCount(2);
});

it('resolves a cash-register route to the register local printer', function () {
    ['order' => $order, 'registerPrinter' => $register] = orderWithRoute(destination: PrintDestination::CashRegister);

    $order->loadMissing(['lines.food.category.printRoutes', 'cashRegister.printer']);
    $tasks = app(OrderPrintRouter::class)->tasks($order);

    $comanda = collect($tasks)->last(fn ($task): bool => $task->type === PrintJobType::DepartmentTicket);

    expect($comanda->printer->id)->toBe($register->id);
});

it('queues a send job and records a print job per document', function () {
    Queue::fake();
    ['order' => $order] = orderWithRoute();

    app(OrderPrinter::class)->print($order);

    Queue::assertPushed(SendToPrinterJob::class, 2);
    expect(PrintJob::where('order_id', $order->id)->where('status', PrintJobStatus::Pending)->count())->toBe(2);
});

it('records a failed print job without queuing when no printer is available', function () {
    Queue::fake();

    $day = EventDay::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => null]);
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $inactive = Printer::factory()->create(['active' => false]);

    PrintRoute::factory()->for($category)->create([
        'service_type' => ServiceType::TableService,
        'destination' => PrintDestination::DepartmentPrinter,
        'printer_id' => $inactive->id,
        'grouped' => true,
    ]);

    $order = Order::place($day, $register, null, 5, null, PaymentMethod::Cash, [
        ['food_id' => $food->id, 'food_name' => $food->name, 'unit_price' => 500, 'quantity' => 1, 'note' => null, 'ingredients' => []],
    ]);

    app(OrderPrinter::class)->print($order);

    Queue::assertNothingPushed();
    expect(PrintJob::where('order_id', $order->id)->where('status', PrintJobStatus::Failed)->count())->toBe(2);
});

it('marks the print job printed once the bytes are sent', function () {
    $order = Order::factory()->create();
    $printJob = PrintJob::create([
        'order_id' => $order->id,
        'type' => PrintJobType::CustomerReceipt,
        'label' => 'Scontrino',
        'status' => PrintJobStatus::Pending,
    ]);

    $connection = Mockery::mock(PrinterConnection::class);
    $connection->shouldReceive('send')->once()->with('1.2.3.4', 9100, 'BYTES');

    (new SendToPrinterJob($printJob->id, '1.2.3.4', 9100, base64_encode('BYTES')))->handle($connection);

    expect($printJob->fresh()->status)->toBe(PrintJobStatus::Printed)
        ->and($printJob->fresh()->printed_at)->not->toBeNull();
});

it('marks the print job failed when the printer is unreachable', function () {
    $order = Order::factory()->create();
    $printJob = PrintJob::create([
        'order_id' => $order->id,
        'type' => PrintJobType::CustomerReceipt,
        'label' => 'Scontrino',
        'status' => PrintJobStatus::Pending,
    ]);

    (new SendToPrinterJob($printJob->id, '1.2.3.4', 9100, 'BYTES'))
        ->failed(new PrinterException('Stampante offline'));

    expect($printJob->fresh()->status)->toBe(PrintJobStatus::Failed)
        ->and($printJob->fresh()->error)->toContain('offline');
});

it('queues printing when an order is placed from the pos', function () {
    Queue::fake();

    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());
    $printer = Printer::factory()->create();
    $register = CashRegister::factory()->create(['printer_id' => $printer->id]);
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    Queue::assertPushed(SendToPrinterJob::class);
});

it('reprints an order from the admin action', function () {
    Queue::fake();
    ['order' => $order] = orderWithRoute();

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListOrders::class)
        ->callAction(TestAction::make('reprint')->table($order));

    Queue::assertPushed(SendToPrinterJob::class, 2);
});
