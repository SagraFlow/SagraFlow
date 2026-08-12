<?php

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\PrintJobStatus;
use App\Exceptions\OrderException;
use App\Exceptions\PrinterException;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\StockReservation;
use App\Printing\Documents\DrawerKick;
use App\Printing\OrderPrinter;
use App\Printing\PrinterConnection;
use App\Settings\EventSettings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.app')] #[Title('Cassa')] class extends Component
{
    public ?int $cashRegisterId = null;

    /**
     * Cart lines keyed by a config hash.
     *
     * @var array<string, array{food_id: int, name: string, unit_price: int, quantity: int, ingredients: array<int, array{ingredient_id: int, name: string, quantity: int, base_quantity: int, surcharge: int}>}>
     */
    #[Locked]
    public array $cart = [];

    public ?int $customizingFoodId = null;

    /** Cart line key currently being edited via the customization modal. */
    public ?string $editingKey = null;

    /** @var array<int, int> ingredient id => chosen quantity */
    public array $customizeQty = [];

    public ?string $customizeNote = null;

    public ?int $tableNumber = null;

    public ?string $customerName = null;

    public int $covers = 0;

    /** Per-cover charge (coperto) frozen when the sale started, in cents. */
    #[Locked]
    public ?int $frozenCoverCharge = null;

    /** Whether the discount applies to the coperto, frozen when the sale started. */
    #[Locked]
    public ?bool $frozenDiscountAppliesToCover = null;

    public ?string $discountType = null;

    /** Raw discount value as typed: euros for a fixed discount, percent otherwise. */
    public ?string $discountValue = null;

    public bool $showDiscount = false;

    public ?string $discountTypeBackup = null;

    public ?string $discountValueBackup = null;

    public bool $showCashModal = false;

    public bool $showCardModal = false;

    public bool $showClearCart = false;

    public bool $showSoldOut = false;

    public bool $showReservationExpired = false;

    public bool $showPrinterIssues = false;

    /**
     * Ingredients that ran short at checkout, shown in the sold-out modal.
     *
     * @var array<int, array{name: string, missing: int}>
     */
    public array $soldOutItems = [];

    /** Id of the stock reservation held while a payment is in progress. */
    #[Locked]
    public ?int $reservationId = null;

    /** Cash tendered, in cents (authoritative). */
    public int $cashReceivedCents = 0;

    /** Raw euro amount typed in the cash field, parsed into cents. */
    public string $cashInput = '';

    public ?int $placedOrderNumber = null;

    public function mount(): void
    {
        $this->cashRegisterId = session('pos_cash_register_id');
    }

    #[Computed]
    public function day(): ?EventDay
    {
        return EventDay::current();
    }

    #[Computed]
    public function cashRegister(): ?CashRegister
    {
        return $this->cashRegisterId !== null
            ? CashRegister::active()->find($this->cashRegisterId)
            : null;
    }

    /**
     * A warning for the cashier about the printers relevant to their work, or
     * null when all is well. Relevant = this register's own printer plus every
     * shared department printer (kitchen/bar) - a broken department printer means
     * the comande won't come out - but not the other registers' printers. Polled
     * by the POS header so problems surface within seconds.
     *
     * The message is kept short: it rides in a badge in the header (never in a
     * band above the menu, which would resize the grid under the cashier's
     * fingers), so it names the most relevant printer and counts the rest.
     *
     * @return array{level: string, message: string}|null
     */
    #[Computed]
    public function printerAlert(): ?array
    {
        $printers = $this->relevantPrinters();

        if ($printers->isEmpty()) {
            return null;
        }

        $inError = $printers->filter(fn (Printer $printer): bool => ! $printer->status->canPrint());

        if ($inError->isNotEmpty()) {
            // The first one is this register's own printer when it is among them,
            // otherwise the alphabetically first department printer.
            $worst = $inError->first();
            $others = $inError->count() - 1;

            return [
                'level' => 'danger',
                'message' => "{$worst->name}: ".mb_strtolower($worst->status->getLabel()).($others > 0 ? " +{$others}" : ''),
            ];
        }

        // Only Held jobs (waiting to retry) are actionable; Failed are terminal
        // and belong to the admin log, not a permanent banner on the till.
        $waiting = PrintJob::query()
            ->whereIn('printer_id', $printers->pluck('id'))
            ->where('status', PrintJobStatus::Held)
            ->count();

        if ($waiting > 0) {
            return ['level' => 'warning', 'message' => "{$waiting} in attesa"];
        }

        return null;
    }

    /**
     * The printers this cashier's work depends on: their own register's printer
     * plus every shared department printer, their own first and the rest
     * alphabetically. Other registers' printers are none of their business.
     *
     * @return Collection<int, Printer>
     */
    protected function relevantPrinters(): Collection
    {
        $registerPrinterId = $this->cashRegister?->printer_id;

        return Printer::query()
            ->active()
            ->where(function ($query) use ($registerPrinterId): void {
                $query->whereDoesntHave('cashRegister'); // department printers

                if ($registerPrinterId !== null) {
                    $query->orWhere('id', $registerPrinterId); // this register's own
                }
            })
            ->get()
            ->sortBy(fn (Printer $printer): array => [
                $printer->id === $registerPrinterId ? 0 : 1,
                $printer->name,
            ])
            ->values();
    }

    /**
     * Every relevant printer that is either unable to print or sitting on jobs
     * waiting to retry, in the same order as the badge. The full picture the
     * badge has no room for, shown when the cashier taps it.
     *
     * @return array<int, array{name: string, status: string, blocked: bool, waiting: int}>
     */
    #[Computed]
    public function printerIssues(): array
    {
        $printers = $this->relevantPrinters();

        if ($printers->isEmpty()) {
            return [];
        }

        $waitingByPrinter = PrintJob::query()
            ->whereIn('printer_id', $printers->pluck('id'))
            ->where('status', PrintJobStatus::Held)
            ->selectRaw('printer_id, count(*) as total')
            ->groupBy('printer_id')
            ->pluck('total', 'printer_id');

        return $printers
            ->map(fn (Printer $printer): array => [
                'name' => $printer->name,
                'status' => $printer->status->getLabel(),
                'blocked' => ! $printer->status->canPrint(),
                'waiting' => (int) ($waitingByPrinter[$printer->id] ?? 0),
            ])
            ->filter(fn (array $issue): bool => $issue['blocked'] || $issue['waiting'] > 0)
            ->values()
            ->all();
    }

    public function openPrinterIssues(): void
    {
        $this->showPrinterIssues = true;
    }

    public function closePrinterIssues(): void
    {
        $this->showPrinterIssues = false;
    }

    /**
     * @return Collection<int, CashRegister>
     */
    #[Computed]
    public function registers(): Collection
    {
        return CashRegister::query()->active()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->active()->ordered()->get();
    }

    /**
     * Active categories with at least one food sellable on the open day.
     *
     * @return Collection<int, array{category: Category, foods: Collection<int, Food>}>
     */
    #[Computed]
    public function menu(): Collection
    {
        $consumption = $this->cartConsumption();

        $foodsByCategory = Food::query()
            ->active()
            ->availableOn($this->day)
            ->with('ingredients')
            ->orderBy('name')
            ->get()
            ->groupBy('category_id');

        return $this->categories
            ->map(fn (Category $category): array => [
                'category' => $category,
                'foods' => $foodsByCategory->get($category->id, collect())
                    ->map(fn (Food $food): array => [
                        'food' => $food,
                        'available' => $this->foodIsAvailable($food, $consumption),
                    ]),
            ])
            ->filter(fn (array $group): bool => $group['foods']->isNotEmpty())
            ->values();
    }

    #[Computed]
    public function customizingFood(): ?Food
    {
        return $this->customizingFoodId !== null
            ? Food::active()->with('ingredients')->find($this->customizingFoodId)
            : null;
    }

    /**
     * Ingredients of the food being customized that can actually be adjusted.
     *
     * @return Collection<int, Ingredient>
     */
    #[Computed]
    public function customizableIngredients(): Collection
    {
        return $this->customizingFood?->ingredients
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->pivot->max_quantity > $ingredient->pivot->min_quantity)
            ->values()
            ?? collect();
    }

    #[Computed]
    public function customizeSurcharge(): int
    {
        $food = $this->customizingFood;

        if ($food === null) {
            return 0;
        }

        return (int) $food->ingredients->sum(function (Ingredient $ingredient): int {
            $qty = $this->customizeQty[$ingredient->id] ?? $ingredient->pivot->quantity;

            return $ingredient->surcharge * max(0, $qty - $ingredient->pivot->quantity);
        });
    }

    #[Computed]
    public function cartTotal(): int
    {
        return collect($this->cart)->sum(fn (array $line): int => $this->lineTotal($line));
    }

    #[Computed]
    public function discountAmount(): int
    {
        $base = $this->cartTotal
            + ($this->discountAppliesToCover ? $this->coverTotal : 0);

        return Order::calculateDiscount(
            $base,
            $this->discountType !== null ? DiscountType::from($this->discountType) : null,
            $this->discountValueForDomain(),
        );
    }

    /**
     * Per-cover charge (coperto) for the current sale, in cents. Frozen once the
     * sale starts so a mid-sale price change never diverges from the receipt.
     */
    #[Computed]
    public function coverCharge(): int
    {
        return $this->frozenCoverCharge ?? app(EventSettings::class)->coverCharge;
    }

    /**
     * Whether the discount applies to the coperto for the current sale, frozen
     * once the sale starts.
     */
    #[Computed]
    public function discountAppliesToCover(): bool
    {
        return $this->frozenDiscountAppliesToCover ?? app(EventSettings::class)->discountAppliesToCover;
    }

    /**
     * Total cover charge for the order: covers x per-cover charge (in cents).
     */
    #[Computed]
    public function coverTotal(): int
    {
        return $this->covers * $this->coverCharge;
    }

    #[Computed]
    public function orderTotal(): int
    {
        return $this->cartTotal - $this->discountAmount + $this->coverTotal;
    }

    #[Computed]
    public function changeAmount(): int
    {
        return $this->cashReceivedCents - $this->orderTotal;
    }

    public function selectRegister(int $id): void
    {
        $this->cashRegisterId = $id;
        session(['pos_cash_register_id' => $id]);
        unset($this->cashRegister);
    }

    public function changeRegister(): void
    {
        $this->cashRegisterId = null;
        session()->forget('pos_cash_register_id');
        unset($this->cashRegister);
    }

    public function logout()
    {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect(route('filament.admin.auth.login'), navigate: false);
    }

    /**
     * Kick the cash drawer via an ESC/POS pulse to the register's local printer.
     * Sent synchronously (not queued) so the drawer pops instantly on press; a
     * failure surfaces as a message instead of failing silently.
     */
    public function openCashDrawer(): void
    {
        $printer = $this->cashRegister?->printer;

        if ($printer === null) {
            $this->dispatch('pos-notice', message: 'Nessuna stampante associata alla cassa.');

            return;
        }

        try {
            app(PrinterConnection::class)->send($printer->ip_address, $printer->port, (new DrawerKick)->render(), timeout: 3);
        } catch (PrinterException) {
            $this->dispatch('pos-notice', message: 'Impossibile aprire il cassetto: stampante non raggiungibile.');
        }
    }

    public function updatedTableNumber(): void
    {
        if ($this->tableNumber !== null && $this->tableNumber > 9999) {
            $this->tableNumber = 9999;
        }
    }

    public function updatedCovers(): void
    {
        $this->covers = max(0, min(999, (int) $this->covers));
    }

    public function incCovers(): void
    {
        if ($this->covers < 999) {
            $this->covers++;
        }
    }

    public function decCovers(): void
    {
        if ($this->covers > 0) {
            $this->covers--;
        }
    }

    /**
     * Whether a payment is under way (a payment modal open or stock held), during
     * which the cart is frozen so it never diverges from the taken reservation.
     */
    protected function paymentInProgress(): bool
    {
        return $this->showCashModal || $this->showCardModal || $this->reservationId !== null;
    }

    public function addFood(int $foodId): void
    {
        if ($this->paymentInProgress()) {
            return;
        }

        $food = Food::active()->with('ingredients')->find($foodId);

        if ($food === null) {
            return;
        }

        if (! $this->foodIsAvailable($food, $this->cartConsumption())) {
            $this->dispatch('pos-notice', message: "«{$food->name}» esaurito.");

            return;
        }

        $this->startSaleIfNeeded();
        $this->addToCart($food, $this->lineIngredients($food));
    }

    /**
     * Freeze the event settings that affect pricing the first time an item is
     * added, so the cart preview and the placed order stay consistent even if
     * the settings change mid-sale.
     */
    protected function startSaleIfNeeded(): void
    {
        if ($this->frozenCoverCharge !== null) {
            return;
        }

        $settings = app(EventSettings::class);
        $this->frozenCoverCharge = $settings->coverCharge;
        $this->frozenDiscountAppliesToCover = $settings->discountAppliesToCover;
    }

    /**
     * Open the customization modal to edit an existing cart line.
     */
    public function editLine(string $key): void
    {
        if ($this->paymentInProgress()) {
            return;
        }

        $line = $this->cart[$key] ?? null;

        if ($line === null) {
            return;
        }

        $this->editingKey = $key;
        $this->customizingFoodId = $line['food_id'];
        $this->customizeNote = $line['note'] ?? null;
        $this->customizeQty = collect($line['ingredients'])
            ->mapWithKeys(fn (array $i): array => [$i['ingredient_id'] => $i['quantity']])
            ->all();
    }

    public function incIngredient(int $ingredientId): void
    {
        $ingredient = $this->customizingFood?->ingredients->firstWhere('id', $ingredientId);

        if ($ingredient !== null && ($this->customizeQty[$ingredientId] ?? 0) < $ingredient->pivot->max_quantity) {
            $this->customizeQty[$ingredientId]++;
        }
    }

    public function decIngredient(int $ingredientId): void
    {
        $ingredient = $this->customizingFood?->ingredients->firstWhere('id', $ingredientId);

        if ($ingredient !== null && ($this->customizeQty[$ingredientId] ?? 0) > $ingredient->pivot->min_quantity) {
            $this->customizeQty[$ingredientId]--;
        }
    }

    public function confirmCustomize(): void
    {
        if ($this->paymentInProgress()) {
            return;
        }

        $food = $this->customizingFood;

        if ($food === null || $this->editingKey === null || ! isset($this->cart[$this->editingKey])) {
            $this->cancelCustomize();

            return;
        }

        $ingredients = $this->lineIngredients($food, $this->customizeQty);
        $note = $this->customizeNote ?: null;
        $newKey = $this->cartKey($food, $ingredients, $note);
        $quantity = $this->cart[$this->editingKey]['quantity'];

        // Build the prospective cart, then commit only if it still fits the stock.
        if ($newKey !== $this->editingKey && isset($this->cart[$newKey])) {
            // The new configuration matches another line: merge into it.
            $cart = $this->cart;
            $cart[$newKey]['quantity'] += $quantity;
            unset($cart[$this->editingKey]);
        } else {
            // Update the line in place, keeping its position in the cart.
            $line = [
                'food_id' => $food->id,
                'name' => $food->name,
                'unit_price' => $food->price,
                'quantity' => $quantity,
                'note' => $note,
                'ingredients' => $ingredients,
            ];

            $cart = collect($this->cart)
                ->mapWithKeys(fn (array $existing, string $key): array => $key === $this->editingKey
                    ? [$newKey => $line]
                    : [$key => $existing])
                ->all();
        }

        if (($over = $this->overStockIngredient($cart)) !== null) {
            // Keep the modal open so the operator can lower the dose.
            $this->dispatch('pos-notice', message: "«{$over}» esaurito.");

            return;
        }

        $this->cart = $cart;
        $this->cancelCustomize();
    }

    public function cancelCustomize(): void
    {
        $this->reset('customizingFoodId', 'customizeQty', 'customizeNote', 'editingKey');
        unset($this->customizingFood);
    }

    public function incrementLine(string $key): void
    {
        if ($this->paymentInProgress() || ! isset($this->cart[$key])) {
            return;
        }

        $cart = $this->cart;
        $cart[$key]['quantity']++;

        if (($over = $this->overStockIngredient($cart)) !== null) {
            $this->dispatch('pos-notice', message: "«{$over}» esaurito.");

            return;
        }

        $this->cart = $cart;
    }

    public function decrementLine(string $key): void
    {
        if ($this->paymentInProgress() || ! isset($this->cart[$key])) {
            return;
        }

        if (--$this->cart[$key]['quantity'] < 1) {
            unset($this->cart[$key]);
        }
    }

    public function openClearCart(): void
    {
        if ($this->paymentInProgress()) {
            return;
        }

        if ($this->cart !== []) {
            $this->showClearCart = true;
        }
    }

    public function cancelClearCart(): void
    {
        $this->showClearCart = false;
    }

    /**
     * Abandon the in-progress order (cart lines and order details).
     */
    public function clearCart(): void
    {
        $this->releaseReservation();
        $this->reset('cart', 'tableNumber', 'customerName', 'covers', 'frozenCoverCharge', 'frozenDiscountAppliesToCover', 'discountType', 'discountValue', 'showClearCart');
    }

    public function openDiscount(): void
    {
        $this->discountTypeBackup = $this->discountType;
        $this->discountValueBackup = $this->discountValue;
        $this->showDiscount = true;
    }

    public function cancelDiscount(): void
    {
        $this->discountType = $this->discountTypeBackup;
        $this->discountValue = $this->discountValueBackup;
        $this->showDiscount = false;
    }

    public function applyDiscount(): void
    {
        $this->showDiscount = false;
    }

    /**
     * Reason the sale cannot be checked out right now, or null when it can.
     * Guards against state that changed after the cart was started (the day
     * being closed, the selected register being deactivated).
     */
    protected function checkoutBlocker(): ?string
    {
        if ($this->day === null) {
            return 'La giornata non è più aperta.';
        }

        if ($this->cashRegisterId !== null && $this->cashRegister === null) {
            return 'La cassa selezionata non è più attiva. Riselezionala.';
        }

        return null;
    }

    public function startCash(): void
    {
        if ($this->cart === []) {
            return;
        }

        if ($blocker = $this->checkoutBlocker()) {
            $this->addError('checkout', $blocker);

            return;
        }

        if (! $this->ensureReserved()) {
            return;
        }

        $this->resetCash();
        $this->showCashModal = true;
    }

    public function updatedCashInput(): void
    {
        $this->cashReceivedCents = $this->eurosToCents($this->cashInput);
    }

    public function addCash(int $cents): void
    {
        $this->cashReceivedCents += $cents;
        $this->cashInput = number_format($this->cashReceivedCents / 100, 2, '.', '');
    }

    public function setExactCash(): void
    {
        $this->cashReceivedCents = $this->orderTotal;
        $this->cashInput = number_format($this->orderTotal / 100, 2, '.', '');
    }

    public function resetCash(): void
    {
        $this->cashReceivedCents = 0;
        $this->cashInput = '';
    }

    /**
     * Parse a euro amount typed by the operator (dot or comma) into cents.
     */
    protected function eurosToCents(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        return (int) round(((float) str_replace(',', '.', $value)) * 100);
    }

    public function closeCash(): void
    {
        $this->showCashModal = false;
        $this->releaseReservation();
    }

    public function confirmCash(): void
    {
        if ($this->changeAmount < 0) {
            return;
        }

        $this->finalize('cash');
    }

    public function startCard(): void
    {
        if ($this->cart === []) {
            return;
        }

        if ($blocker = $this->checkoutBlocker()) {
            $this->addError('checkout', $blocker);

            return;
        }

        if (! $this->ensureReserved()) {
            return;
        }

        $this->showCardModal = true;
    }

    public function closeCard(): void
    {
        $this->showCardModal = false;
        $this->releaseReservation();
    }

    public function cardToCash(): void
    {
        // Keep the reservation: the sale is still going, only the tender changes.
        $this->showCardModal = false;
        $this->startCash();
    }

    public function confirmCard(): void
    {
        $this->finalize('card');
    }

    protected function finalize(string $method): void
    {
        if ($this->cart === []) {
            return;
        }

        if ($blocker = $this->checkoutBlocker()) {
            $this->addError('checkout', $blocker);

            return;
        }

        // Stock was held when the payment started: claim that hold up front, so
        // the reaper cannot give its units back between here and the commit, and
        // let the order skip its own decrement. When the hold is already gone
        // (expired mid-payment and reaped) place() decrements at commit instead
        // and may still fail on a shortage.
        $reservation = $this->reservationId !== null ? StockReservation::find($this->reservationId) : null;
        $holdClaimed = $reservation !== null && $reservation->claim();
        $consumeStock = ! $holdClaimed;

        // The order is built from the frozen cart snapshot; the ids only re-link
        // the (nullable) foreign keys and become null if the record is gone.
        $existingFoodIds = Food::whereIn('id', collect($this->cart)->pluck('food_id'))
            ->pluck('id')
            ->all();

        $existingIngredientIds = Ingredient::whereIn('id', collect($this->cart)
            ->flatMap(fn (array $line): array => array_column($line['ingredients'], 'ingredient_id')))
            ->pluck('id')
            ->all();

        $items = collect($this->cart)
            ->map(fn (array $line): array => [
                'food_id' => in_array($line['food_id'], $existingFoodIds, true) ? $line['food_id'] : null,
                'food_name' => $line['name'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'note' => $line['note'] ?? null,
                'ingredients' => collect($line['ingredients'])
                    ->map(fn (array $i): array => [
                        'ingredient_id' => in_array($i['ingredient_id'], $existingIngredientIds, true) ? $i['ingredient_id'] : null,
                        'ingredient_name' => $i['name'],
                        'quantity' => $i['quantity'],
                        'base_quantity' => $i['base_quantity'],
                        'surcharge' => $i['surcharge'],
                    ])
                    ->all(),
            ])
            ->values()
            ->all();

        try {
            $order = Order::place(
                $this->day,
                $this->cashRegister,
                auth()->user(),
                $this->tableNumber ?: null,
                $this->customerName ?: null,
                PaymentMethod::from($method),
                $items,
                $this->discountType !== null ? DiscountType::from($this->discountType) : null,
                $this->discountValueForDomain(),
                $this->covers,
                $this->coverCharge,
                $this->discountAppliesToCover,
                $method === 'cash' ? $this->cashReceivedCents : null,
                consumeStock: $consumeStock,
            );
        } catch (OrderException $e) {
            if ($holdClaimed) {
                // The order the hold was claimed for never existed: give the
                // units back. Under a hold the failure is never a shortage, and
                // the payment screen's heartbeat takes a fresh hold.
                StockReservation::restoreHeld($reservation->held ?? []);
                $this->reservationId = null;
                $this->addError('checkout', $e->getMessage());

                return;
            }

            // Without a hold the stock is decremented at commit, so it can have
            // run out: then surface the missing ingredients.
            if (($shortfall = $this->stockShortfall($this->cart)) !== []) {
                $this->reservationId = null;
                $this->openSoldOut($shortfall);
            } else {
                $this->addError('checkout', $e->getMessage());
            }

            return;
        }

        // Printing must never roll back a placed order: queue it, swallow setup errors.
        try {
            app(OrderPrinter::class)->print($order);
        } catch (Throwable $e) {
            report($e);
        }

        $this->placedOrderNumber = $order->number;
        $this->reset('cart', 'tableNumber', 'customerName', 'covers', 'frozenCoverCharge', 'frozenDiscountAppliesToCover', 'discountType', 'discountValue', 'showDiscount', 'showCashModal', 'showCardModal', 'cashReceivedCents', 'cashInput', 'reservationId', 'showSoldOut', 'soldOutItems', 'showReservationExpired');
    }

    public function newOrder(): void
    {
        $this->placedOrderNumber = null;
    }

    public function money(int $cents): string
    {
        return '€ '.number_format($cents / 100, 2, ',', '.');
    }

    /**
     * Per-portion surcharge of a cart line (in cents).
     *
     * @param  array{ingredients: array<int, array{quantity: int, base_quantity: int, surcharge: int}>}  $line
     */
    public function lineSurcharge(array $line): int
    {
        return (int) collect($line['ingredients'])
            ->sum(fn (array $i): int => $i['surcharge'] * max(0, $i['quantity'] - $i['base_quantity']));
    }

    /**
     * Total for a cart line, surcharges included (in cents).
     *
     * @param  array{unit_price: int, quantity: int, ingredients: array<int, array<string, int>>}  $line
     */
    public function lineTotal(array $line): int
    {
        return ($line['unit_price'] + $this->lineSurcharge($line)) * $line['quantity'];
    }

    /**
     * Human-readable summary of the deviations from the base recipe.
     *
     * @param  array{ingredients: array<int, array{name: string, quantity: int, base_quantity: int}>}  $line
     */
    public function lineNotes(array $line): string
    {
        return collect($line['ingredients'])
            ->filter(fn (array $i): bool => $i['quantity'] !== $i['base_quantity'])
            ->map(function (array $i): string {
                if ($i['quantity'] === 0) {
                    return 'senza '.$i['name'];
                }

                $delta = $i['quantity'] - $i['base_quantity'];

                return ($delta > 0 ? '+'.$delta : (string) $delta).' '.$i['name'];
            })
            ->implode(', ');
    }

    /**
     * Snapshot the food's ingredients for a cart line, using chosen quantities when given.
     *
     * @param  array<int, int>  $chosen
     * @return array<int, array{ingredient_id: int, name: string, quantity: int, base_quantity: int, surcharge: int}>
     */
    protected function lineIngredients(Food $food, array $chosen = []): array
    {
        return $food->ingredients->map(fn (Ingredient $ingredient): array => [
            'ingredient_id' => $ingredient->id,
            'name' => $ingredient->name,
            'quantity' => $chosen[$ingredient->id] ?? $ingredient->pivot->quantity,
            'base_quantity' => $ingredient->pivot->quantity,
            'surcharge' => $ingredient->surcharge,
        ])->all();
    }

    /**
     * Units of each ingredient consumed by a cart (defaults to the current
     * cart): per-portion dose x number of portions, summed across all lines.
     *
     * @param  array<string, array<string, mixed>>|null  $cart
     * @return array<int, int> ingredient id => units
     */
    protected function cartConsumption(?array $cart = null): array
    {
        $consumption = [];

        foreach ($cart ?? $this->cart as $line) {
            foreach ($line['ingredients'] as $ingredient) {
                $units = $ingredient['quantity'] * $line['quantity'];

                if ($units > 0) {
                    $consumption[$ingredient['ingredient_id']] = ($consumption[$ingredient['ingredient_id']] ?? 0) + $units;
                }
            }
        }

        return $consumption;
    }

    /**
     * The name of the first tracked ingredient the given cart would oversell,
     * or null when everything fits. Untracked ingredients (null stock) never
     * block.
     *
     * @param  array<string, array<string, mixed>>  $cart
     */
    protected function overStockIngredient(array $cart): ?string
    {
        return $this->stockShortfall($cart)[0]['name'] ?? null;
    }

    /**
     * Tracked ingredients the given cart would oversell, each with how many
     * units are missing. Empty when everything fits (untracked ingredients
     * never appear).
     *
     * @param  array<string, array<string, mixed>>  $cart
     * @return array<int, array{name: string, missing: int}>
     */
    protected function stockShortfall(array $cart): array
    {
        $consumption = $this->cartConsumption($cart);

        if ($consumption === []) {
            return [];
        }

        $shortfall = [];

        foreach (Ingredient::whereIn('id', array_keys($consumption))->get() as $ingredient) {
            if ($ingredient->stock !== null && $consumption[$ingredient->id] > $ingredient->stock) {
                $shortfall[] = ['name' => $ingredient->name, 'missing' => $consumption[$ingredient->id] - $ingredient->stock];
            }
        }

        return $shortfall;
    }

    /**
     * Close the payment modals and surface the sold-out modal listing the
     * ingredients that ran short, so the operator can amend the order.
     *
     * @param  array<int, array{name: string, missing: int}>  $shortfall
     */
    protected function openSoldOut(array $shortfall): void
    {
        $this->showCashModal = false;
        $this->showCardModal = false;
        $this->soldOutItems = $shortfall;
        $this->showSoldOut = true;
    }

    public function closeSoldOut(): void
    {
        $this->showSoldOut = false;
        $this->soldOutItems = [];
    }

    /**
     * Abandon a payment left open past the maximum hold: release the stock, exit
     * the payment modals back to the (intact) cart, and warn the cashier.
     */
    protected function expirePayment(): void
    {
        $this->releaseReservation();
        $this->showCashModal = false;
        $this->showCardModal = false;
        $this->showReservationExpired = true;
    }

    public function closeReservationExpired(): void
    {
        $this->showReservationExpired = false;
    }

    /**
     * Ensure the cart's ingredient stock is held before taking payment. Returns
     * false (and opens the sold-out modal) when the stock is short, so the
     * customer is never charged for goods that cannot be served.
     */
    protected function ensureReserved(): bool
    {
        if ($this->reservationId !== null && StockReservation::whereKey($this->reservationId)->exists()) {
            return true; // already holding this cart's stock
        }

        $reservation = StockReservation::reserve($this->cartConsumption(), (int) config('inventory.reservation_ttl', 300));

        if ($reservation === null) {
            $this->openSoldOut($this->stockShortfall($this->cart));

            return false;
        }

        $this->reservationId = $reservation->id;

        return true;
    }

    /**
     * Give back any stock held for this checkout (payment cancelled/abandoned).
     */
    protected function releaseReservation(): void
    {
        if ($this->reservationId !== null) {
            StockReservation::find($this->reservationId)?->release();
            $this->reservationId = null;
        }
    }

    /**
     * Heartbeat polled by the browser while a payment modal is open: keeps the
     * reservation alive so a slow payment never loses its hold, while the TTL
     * still frees holds from browsers that are actually gone. If the hold was
     * already released (e.g. the tab was suspended past the TTL), re-acquire it;
     * when the stock is gone, stop the payment and show the sold-out modal
     * before the customer is charged.
     */
    public function keepReservationAlive(): void
    {
        if (! $this->showCashModal && ! $this->showCardModal) {
            return;
        }

        $ttl = (int) config('inventory.reservation_ttl', 300);

        if ($this->reservationId !== null && ($reservation = StockReservation::find($this->reservationId)) !== null) {
            // Absolute cap: a payment screen left open too long (from when the
            // payment started) is treated as abandoned, freeing the held stock.
            $maxHold = (int) config('inventory.max_hold', 900);

            if ($reservation->created_at !== null && $reservation->created_at->addSeconds($maxHold)->isPast()) {
                $this->expirePayment();

                return;
            }

            $reservation->renew($ttl);

            return;
        }

        $reservation = StockReservation::reserve($this->cartConsumption(), $ttl);

        if ($reservation === null) {
            $this->reservationId = null;
            $this->openSoldOut($this->stockShortfall($this->cart));

            return;
        }

        $this->reservationId = $reservation->id;
    }

    /**
     * Whether one more base portion of the food fits the tracked ingredient
     * stock left after the current cart. Untracked ingredients are unlimited.
     *
     * @param  array<int, int>  $consumption  ingredient id => units already in cart
     */
    protected function foodIsAvailable(Food $food, array $consumption): bool
    {
        foreach ($food->ingredients as $ingredient) {
            if ($ingredient->stock === null) {
                continue;
            }

            if ($ingredient->stock - ($consumption[$ingredient->id] ?? 0) < $ingredient->pivot->quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{ingredient_id: int, name: string, quantity: int, base_quantity: int, surcharge: int}>  $ingredients
     */
    /**
     * Stable identity of a cart line: same food + ingredient choices + note merge.
     *
     * @param  array<int, array{ingredient_id: int, quantity: int}>  $ingredients
     */
    protected function cartKey(Food $food, array $ingredients, ?string $note): string
    {
        $signature = collect($ingredients)
            ->map(fn (array $i): string => $i['ingredient_id'].':'.$i['quantity'])
            ->sort()
            ->implode(',');

        return md5($food->id.'|'.$signature.'|'.($note ?? ''));
    }

    /**
     * @param  array<int, array{ingredient_id: int, name: string, quantity: int, base_quantity: int, surcharge: int}>  $ingredients
     */
    protected function addToCart(Food $food, array $ingredients, ?string $note = null, int $quantity = 1): void
    {
        $key = $this->cartKey($food, $ingredients, $note);

        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity'] += $quantity;

            return;
        }

        $this->cart[$key] = [
            'food_id' => $food->id,
            'name' => $food->name,
            'unit_price' => $food->price,
            'quantity' => $quantity,
            'note' => $note,
            'ingredients' => $ingredients,
        ];
    }

    protected function discountValueForDomain(): ?int
    {
        if ($this->discountType === null || $this->discountValue === null || trim($this->discountValue) === '') {
            return null;
        }

        $value = (float) str_replace(',', '.', $this->discountValue);

        return $this->discountType === DiscountType::Fixed->value
            ? (int) round($value * 100)
            : (int) round($value);
    }
};
