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
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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

    public ?string $discountType = null;

    public ?float $discountValue = null;

    public bool $showDiscount = false;

    public ?string $discountTypeBackup = null;

    public ?float $discountValueBackup = null;

    public bool $showCashModal = false;

    public bool $showCardModal = false;

    public bool $showClearCart = false;

    public float $cashReceived = 0;

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
        return Order::calculateDiscount(
            $this->cartTotal,
            $this->discountType !== null ? DiscountType::from($this->discountType) : null,
            $this->discountValueForDomain(),
        );
    }

    #[Computed]
    public function orderTotal(): int
    {
        return $this->cartTotal - $this->discountAmount;
    }

    #[Computed]
    public function cashReceivedCents(): int
    {
        return (int) round($this->cashReceived * 100);
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

        $this->addToCart($food, $this->lineIngredients($food));
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
        $this->reset('cart', 'tableNumber', 'customerName', 'covers', 'discountType', 'discountValue', 'showClearCart');
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

    public function startCash(): void
    {
        if ($this->cart === []) {
            return;
        }

        $this->cashReceived = 0;
        $this->showCashModal = true;
    }

    public function addCash(float $amount): void
    {
        $this->cashReceived += $amount;
    }

    public function setExactCash(): void
    {
        $this->cashReceived = $this->orderTotal / 100;
    }

    public function resetCash(): void
    {
        $this->cashReceived = 0;
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

        $items = collect($this->cart)
            ->map(function (array $line): ?array {
                $food = Food::find($line['food_id']);

                if ($food === null) {
                    return null;
                }

                return [
                    'food' => $food,
                    'quantity' => $line['quantity'],
                    'note' => $line['note'] ?? null,
                    'ingredients' => collect($line['ingredients'])
                        ->map(fn (array $i): ?array => ($ingredient = Ingredient::find($i['ingredient_id'])) !== null
                            ? ['ingredient' => $ingredient, 'quantity' => $i['quantity']]
                            : null)
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
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
            );
        } catch (OrderException $e) {
            $this->addError('checkout', $e->getMessage());

            return;
        }

        $this->placedOrderNumber = $order->number;
        $this->reset('cart', 'tableNumber', 'customerName', 'covers', 'discountType', 'discountValue', 'showDiscount', 'showCashModal', 'showCardModal', 'cashReceived');
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
        if ($this->discountType === null || $this->discountValue === null) {
            return null;
        }

        return $this->discountType === DiscountType::Fixed->value
            ? (int) round($this->discountValue * 100)
            : (int) $this->discountValue;
    }
};
