<?php

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Exceptions\OrderException;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Order;
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
        $foodsByCategory = Food::query()
            ->active()
            ->availableOn($this->day)
            ->orderBy('name')
            ->get()
            ->groupBy('category_id');

        return $this->categories
            ->map(fn (Category $category): array => [
                'category' => $category,
                'foods' => $foodsByCategory->get($category->id, collect()),
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
     * Open the cash drawer. No-op until printing is wired up: the drawer is
     * kicked via an ESC/POS pulse sent to the register's local printer.
     */
    public function openCashDrawer(): void
    {
        // TODO: send the drawer-kick command to $this->cashRegister->printer.
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

    public function addFood(int $foodId): void
    {
        $food = Food::active()->with('ingredients')->find($foodId);

        if ($food === null) {
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
        $food = $this->customizingFood;

        if ($food !== null && $this->editingKey !== null && isset($this->cart[$this->editingKey])) {
            $ingredients = $this->lineIngredients($food, $this->customizeQty);
            $note = $this->customizeNote ?: null;
            $newKey = $this->cartKey($food, $ingredients, $note);
            $quantity = $this->cart[$this->editingKey]['quantity'];

            if ($newKey !== $this->editingKey && isset($this->cart[$newKey])) {
                // The new configuration matches another line: merge into it.
                $this->cart[$newKey]['quantity'] += $quantity;
                unset($this->cart[$this->editingKey]);
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

                $this->cart = collect($this->cart)
                    ->mapWithKeys(fn (array $existing, string $key): array => $key === $this->editingKey
                        ? [$newKey => $line]
                        : [$key => $existing])
                    ->all();
            }
        }

        $this->cancelCustomize();
    }

    public function cancelCustomize(): void
    {
        $this->reset('customizingFoodId', 'customizeQty', 'customizeNote', 'editingKey');
        unset($this->customizingFood);
    }

    public function incrementLine(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity']++;
        }
    }

    public function decrementLine(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if (--$this->cart[$key]['quantity'] < 1) {
            unset($this->cart[$key]);
        }
    }

    public function openClearCart(): void
    {
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

        $this->showCardModal = true;
    }

    public function closeCard(): void
    {
        $this->showCardModal = false;
    }

    public function cardToCash(): void
    {
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
            );
        } catch (OrderException $e) {
            $this->addError('checkout', $e->getMessage());

            return;
        }

        $this->placedOrderNumber = $order->number;
        $this->reset('cart', 'tableNumber', 'customerName', 'covers', 'frozenCoverCharge', 'frozenDiscountAppliesToCover', 'discountType', 'discountValue', 'showDiscount', 'showCashModal', 'showCardModal', 'cashReceivedCents', 'cashInput');
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
