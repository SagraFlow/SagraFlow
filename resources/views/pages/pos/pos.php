<?php

use App\CardPayments\PaymentRunner;
use App\Enums\CardTransactionStatus;
use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Exceptions\OrderException;
use App\Exceptions\PrinterException;
use App\Jobs\SendCardPaymentJob;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\MenuTab;
use App\Models\MenuTabItem;
use App\Models\Order;
use App\Models\StockReservation;
use App\Printing\Documents\DrawerKick;
use App\Printing\OrderPrinter;
use App\Printing\PrinterConnection;
use App\Settings\EventSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.app')] #[Title('Cassa')] class extends Component
{
    /** Digits a table number can have, which is what the column holds. */
    public const TABLE_DIGITS = 4;

    /** Digits a covers count can have: more people than any hall seats. */
    public const COVERS_DIGITS = 3;

    public ?int $cashRegisterId = null;

    /**
     * Cart lines keyed by a config hash.
     *
     * @var array<string, array{food_id: int, name: string, unit_price: int, quantity: int, ingredients: array<int, array{ingredient_id: int, name: string, quantity: int, base_quantity: int, surcharge: int}>}>
     */
    #[Locked]
    public array $cart = [];

    /** Board currently shown, null for the generated "Tutto" tab. */
    #[Locked]
    public ?int $selectedTabId = null;

    /** Whether the board is being laid out rather than sold from. */
    #[Locked]
    public bool $configuringBoard = false;

    /** Cell whose action sheet is open, in config mode. */
    #[Locked]
    public ?int $editingSlot = null;

    /** Cell picked up and waiting for a destination tap. */
    #[Locked]
    public ?int $movingSlot = null;

    public bool $showKeyPicker = false;

    public string $keySearch = '';

    public bool $showBoardForm = false;

    /** Whether the board form is making a new board rather than editing one. */
    #[Locked]
    public bool $creatingBoard = false;

    public bool $showDeleteBoard = false;

    public bool $showStationBoards = false;

    public string $boardName = '';

    public string $boardDescription = '';

    public int $boardColumns = 5;

    public int $boardRows = 4;

    public ?int $customizingFoodId = null;

    /** Cart line key currently being edited via the customization modal. */
    public ?string $editingKey = null;

    /** @var array<int, int> ingredient id => chosen quantity */
    public array $customizeQty = [];

    public ?string $customizeNote = null;

    /**
     * Where the order goes, as a ServiceType value, or null while the cashier
     * has yet to say. Nothing infers it from the table number any more: it is a
     * step of its own, and an order cannot be paid before it is taken.
     */
    #[Locked]
    public ?string $serviceType = null;

    /** The chosen table, set with the table-service choice and null on a pickup. */
    #[Locked]
    public ?int $tableNumber = null;

    public bool $showService = false;

    /**
     * Digits the table keypad opens on. The pad itself is pressed in the
     * browser: only what it confirms comes back here, so this is a starting
     * value, not a running one.
     */
    #[Locked]
    public string $tableInput = '';

    public ?string $customerName = null;

    /**
     * How many people the order is laid for, or null while the cashier has yet
     * to say. No covers is a number like any other: it is pressed, not left
     * blank, so a forgotten coperto cannot ride out on a paid order.
     */
    #[Locked]
    public ?int $covers = null;

    public bool $showCovers = false;

    /** Digits the covers keypad opens on, pressed in the browser like the table's. */
    #[Locked]
    public string $coversInput = '';

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

    /** Confirming an order that costs nothing: a discount covered it all. */
    public bool $showFreeOrder = false;

    public bool $showClearCart = false;

    /** Cart line whose removal is waiting to be confirmed. */
    #[Locked]
    public ?string $removingKey = null;

    public bool $showSoldOut = false;

    public bool $showReservationExpired = false;

    /**
     * Ingredients that ran short at checkout, shown in the sold-out modal.
     *
     * @var array<int, array{name: string, missing: int}>
     */
    public array $soldOutItems = [];

    /** Id of the stock reservation held while a payment is in progress. */
    #[Locked]
    public ?int $reservationId = null;

    /**
     * The card payment attempt on the terminal, when this station has one.
     * Null means the card is being taken the old way: on the terminal by hand,
     * with the cashier confirming what she saw.
     */
    #[Locked]
    public ?int $cardTransactionId = null;

    /**
     * The station currently holding this station's terminal, when the card flow
     * could not even start. Kept apart from "no terminal at all": there the
     * cashier is the one working the POS and confirms what she saw, while here
     * the POS is busy with somebody else's customer and there is nothing for
     * her to have seen.
     */
    #[Locked]
    public ?string $terminalBusyWith = null;

    /**
     * Set when the terminal that was busy has since come free. It is only an
     * announcement: the cashier presses when she has the terminal in front of
     * her, because a POS that has just finished is still in the other cashier's
     * hands - the customer is taking their card back, the receipt is being torn
     * off - and an amount sent then would appear on a device somebody else is
     * holding.
     */
    #[Locked]
    public bool $terminalFreeAgain = false;

    /**
     * A payment on this station that ended without an answer and has not been
     * settled: sending another one before somebody has looked at the terminal
     * is how a customer gets charged twice.
     *
     * @var array{amount: string, at: string}|null
     */
    #[Locked]
    public ?array $unresolvedPayment = null;

    /** Set once the cashier says she has looked. Lasts for this sale only. */
    #[Locked]
    public bool $unresolvedAcknowledged = false;

    /** Cash tendered, in cents (authoritative). */
    public int $cashReceivedCents = 0;

    /** Raw euro amount typed in the cash field, parsed into cents. */
    public string $cashInput = '';

    public ?int $placedOrderNumber = null;

    public function mount(): void
    {
        $this->cashRegisterId = session('pos_cash_register_id');
        $this->selectedTabId = $this->openingTabId();
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
            ? CashRegister::active()->with('boards')->find($this->cashRegisterId)
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
     * @return Collection<int, array{category: Category, foods: Collection<int, array{food: Food, available: bool, portionsLeft: ?int}>}>
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
                        'portionsLeft' => $this->foodPortionsLeft($food, $consumption),
                    ]),
            ])
            ->filter(fn (array $group): bool => $group['foods']->isNotEmpty())
            ->values();
    }

    /**
     * Every board, in the order they were created. That order is only the
     * starting point for a station's own bar, which is then arranged per
     * station. The generated "Tutto" tab is not one of them: it is always there
     * and never configured.
     *
     * @return Collection<int, MenuTab>
     */
    #[Computed]
    public function tabs(): Collection
    {
        return MenuTab::query()->ordered()->with('items')->get();
    }

    /**
     * This station's tab bar, in order: each entry is a board, or the generated
     * "Tutto" tab (a null board) which takes a place in the bar like any other.
     *
     * With nothing stored the bar is "Tutto" first, then every board in the
     * order the organiser gave them: a fresh install needs no setup. Boards
     * created after a station was arranged land at the end, shown, so a new
     * board is never invisible everywhere by accident.
     *
     * @return Collection<int, array{tab: ?MenuTab, visible: bool}>
     */
    #[Computed]
    public function stationLayout(): Collection
    {
        $boards = $this->tabs;
        $stored = $this->cashRegister?->boards ?? collect();

        if ($stored->isEmpty()) {
            return collect([['tab' => null, 'visible' => true]])
                ->concat($boards->map(fn (MenuTab $tab): array => ['tab' => $tab, 'visible' => true]))
                ->values();
        }

        $byId = $boards->keyBy('id');
        $entries = collect();
        $placed = [];

        foreach ($stored as $row) {
            if ($row->menu_tab_id === null) {
                // The complete tab is the safety net: it can be moved, never hidden.
                $entries->push(['tab' => null, 'visible' => true]);

                continue;
            }

            if (($tab = $byId->get($row->menu_tab_id)) !== null) {
                $placed[] = $tab->id;
                $entries->push(['tab' => $tab, 'visible' => $row->visible]);
            }
        }

        foreach ($boards as $tab) {
            if (! in_array($tab->id, $placed, true)) {
                $entries->push(['tab' => $tab, 'visible' => true]);
            }
        }

        if (! $entries->contains(fn (array $entry): bool => $entry['tab'] === null)) {
            $entries->prepend(['tab' => null, 'visible' => true]);
        }

        return $entries->values();
    }

    /**
     * The bar on screen: everything while arranging it, only what this station
     * shows while selling.
     *
     * @return Collection<int, array{tab: ?MenuTab, visible: bool}>
     */
    #[Computed]
    public function barEntries(): Collection
    {
        return $this->stationLayout->where('visible')->values();
    }

    /**
     * The board this station opens on: the first one it shows. There is nothing
     * else to keep in step, which is why no "default board" is stored anywhere.
     */
    protected function openingTabId(): ?int
    {
        return $this->stationLayout->firstWhere('visible')['tab']?->id;
    }

    /**
     * The board on screen, or null while the "Tutto" tab is showing. A board
     * that was deleted or deactivated mid-service falls back to "Tutto" instead
     * of leaving the cashier on an empty screen.
     */
    #[Computed]
    public function selectedTab(): ?MenuTab
    {
        if ($this->selectedTabId === null) {
            return null;
        }

        // Only what this station shows can be worked on here: a board hidden
        // here is arranged from a station that shows it, or shown again first.
        return $this->barEntries->pluck('tab')->filter()->firstWhere('id', $this->selectedTabId);
    }

    /**
     * The cells of the selected board, in order, one entry per cell: null for an
     * empty cell, otherwise the food and whether it can still be sold.
     *
     * A food that is not on tonight's menu leaves its cell empty rather than
     * letting the others slide up: the whole point of a board is that a key is
     * always in the same place, on every evening of the sagra.
     *
     * While laying the board out that rule does not apply at all: every key that
     * was placed shows, plain and available, as if the whole menu were on. The
     * board is built once for the whole sagra, and what a given evening serves
     * is a question for service time. The keys thin out on their own on the way
     * out of config mode.
     *
     * @return array<int, array{food: Food, available: bool, portionsLeft: ?int}|null>
     */
    #[Computed]
    public function board(): array
    {
        $tab = $this->selectedTab;

        if ($tab === null) {
            return [];
        }

        $placedFoodIds = $tab->items->pluck('food_id');

        $foods = $this->configuringBoard
            ? Food::query()->whereIn('id', $placedFoodIds)->get()->keyBy('id')
            : Food::query()
                ->active()
                ->availableOn($this->day)
                ->with('ingredients')
                ->whereIn('id', $placedFoodIds)
                ->get()
                ->keyBy('id');

        $consumption = $this->configuringBoard ? [] : $this->cartConsumption();
        $cells = array_fill(0, $tab->capacity(), null);

        foreach ($tab->items as $item) {
            $food = $foods->get($item->food_id);

            // Beyond the board (it was shrunk), or off tonight's menu while
            // selling: the cell stays empty.
            if ($food === null || $item->slot >= $tab->capacity()) {
                continue;
            }

            $cells[$item->slot] = [
                'food' => $food,
                'available' => $this->configuringBoard || $this->foodIsAvailable($food, $consumption),
                // No stock figure while laying the board out: the keys are shown
                // there as they will be, not as tonight happens to have them.
                'portionsLeft' => $this->configuringBoard ? null : $this->foodPortionsLeft($food, $consumption),
            ];
        }

        return $cells;
    }

    public function selectTab(?int $tabId = null): void
    {
        $this->selectedTabId = $tabId;
        $this->resetBoardEditing();
    }

    /**
     * Lay out the boards from the till itself: the organiser configures on the
     * very tablet, at the very size, the cashier will use.
     */
    public function enterBoardConfig(): void
    {
        // Only a sale that can still be taken is worth protecting: with no day
        // open the cart cannot be paid anyway, and refusing here would be mute,
        // the notice living in a header that screen does not render.
        if ($this->day !== null && $this->cart !== []) {
            $this->dispatch('pos-notice', message: 'Concludi o annulla l\'ordine prima di modificare le schede.');

            return;
        }

        $this->configuringBoard = true;
        $this->refreshBoards();
    }

    public function exitBoardConfig(): void
    {
        $this->configuringBoard = false;
        $this->resetBoardEditing();
        $this->refreshBoards();
    }

    protected function resetBoardEditing(): void
    {
        $this->reset('editingSlot', 'movingSlot', 'showKeyPicker', 'keySearch', 'showBoardForm', 'creatingBoard', 'showDeleteBoard', 'showStationBoards');
    }

    /**
     * A cell was tapped while laying out the board. Empty cells open the food
     * picker, taken cells open their actions, and a cell picked up for a move
     * lands on the next tap (swapping with whatever is there).
     */
    public function tapCell(int $slot): void
    {
        $tab = $this->selectedTab;

        if (! $this->configuringBoard || $tab === null || $slot < 0 || $slot >= $tab->capacity()) {
            return;
        }

        if ($this->movingSlot !== null) {
            $this->dropOn($slot);

            return;
        }

        $this->editingSlot = $slot;

        if ($tab->items->firstWhere('slot', $slot) === null) {
            $this->showKeyPicker = true;
            $this->keySearch = '';
        }
    }

    /**
     * Land the cell being moved on its destination, swapping the two keys when
     * the destination is taken. Both rows are dropped and rewritten so the
     * unique index on (board, cell) never sees a duplicate mid-swap.
     */
    protected function dropOn(int $slot): void
    {
        $tab = $this->selectedTab;
        $source = $tab?->items->firstWhere('slot', $this->movingSlot);

        if ($tab === null || $source === null || $slot === $this->movingSlot) {
            $this->reset('movingSlot');

            return;
        }

        $destination = $tab->items->firstWhere('slot', $slot);
        $vacated = $source->slot;

        DB::transaction(function () use ($tab, $source, $destination, $slot, $vacated): void {
            $movedFoodId = $source->food_id;
            $swappedFoodId = $destination?->food_id;

            $source->delete();
            $destination?->delete();

            MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $movedFoodId, 'slot' => $slot]);

            if ($swappedFoodId !== null) {
                MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $swappedFoodId, 'slot' => $vacated]);
            }
        });

        $this->reset('movingSlot', 'editingSlot');
        $this->refreshBoards();
    }

    /**
     * Drop the cached boards after a layout change, so the grid on screen shows
     * what was just saved.
     */
    protected function refreshBoards(): void
    {
        unset($this->cashRegister, $this->tabs, $this->stationLayout, $this->barEntries, $this->selectedTab, $this->board, $this->placeableFoods);
    }

    public function startMove(): void
    {
        $this->movingSlot = $this->editingSlot;
        $this->editingSlot = null;
    }

    public function cancelCellActions(): void
    {
        $this->reset('editingSlot', 'movingSlot');
    }

    public function openKeyPicker(): void
    {
        $this->showKeyPicker = true;
        $this->keySearch = '';
    }

    public function closeKeyPicker(): void
    {
        $this->reset('showKeyPicker', 'keySearch', 'editingSlot');
    }

    /**
     * Put a food on the cell being edited, replacing whatever was there.
     */
    public function placeKey(int $foodId): void
    {
        $tab = $this->selectedTab;

        if (! $this->configuringBoard || $tab === null || $this->editingSlot === null) {
            return;
        }

        // A key twice on the same board is never intentional.
        if ($tab->items->firstWhere('food_id', $foodId) !== null) {
            $this->dispatch('pos-notice', message: 'Questa pietanza è già su questa scheda.');

            return;
        }

        MenuTabItem::updateOrCreate(
            ['menu_tab_id' => $tab->id, 'slot' => $this->editingSlot],
            ['food_id' => $foodId],
        );

        $this->closeKeyPicker();
        $this->refreshBoards();
    }

    public function removeKey(): void
    {
        $tab = $this->selectedTab;

        if ($this->configuringBoard && $tab !== null && $this->editingSlot !== null) {
            $tab->items()->where('slot', $this->editingSlot)->delete();
            $this->refreshBoards();
        }

        $this->reset('editingSlot');
    }

    /**
     * Open the board form, on the selected board or on a brand new one.
     */
    public function openBoardForm(bool $new = false): void
    {
        // Creating is a flag, never "nothing selected": clearing the selection
        // would send the screen behind the form back to another board, and the
        // organiser would lose their place for as long as the form is open.
        $this->creatingBoard = $new;
        $tab = $new ? null : $this->selectedTab;

        $this->boardName = $tab?->name ?? '';
        $this->boardDescription = $tab?->description ?? '';
        $this->boardColumns = $tab?->columns ?? 5;
        $this->boardRows = $tab?->rows ?? 4;
        $this->showBoardForm = true;
    }

    public function closeBoardForm(): void
    {
        $this->reset('showBoardForm', 'creatingBoard');
    }

    public function saveBoard(): void
    {
        $this->validate([
            'boardName' => 'required|string|max:100',
            'boardDescription' => 'nullable|string|max:150',
            'boardColumns' => 'required|integer|min:1|max:12',
            'boardRows' => 'required|integer|min:1|max:12',
        ], attributes: [
            'boardName' => 'nome',
            'boardDescription' => 'descrizione',
            'boardColumns' => 'colonne',
            'boardRows' => 'righe',
        ]);

        $tab = $this->creatingBoard ? null : $this->selectedTab;

        if ($tab === null) {
            $tab = MenuTab::create([
                'name' => $this->boardName,
                'description' => $this->boardDescription ?: null,
                'columns' => $this->boardColumns,
                'rows' => $this->boardRows,
            ]);

            $this->selectedTabId = $tab->id;
            $this->closeBoardForm();
            $this->refreshBoards();

            return;
        }

        // Shrinking would silently drop the keys left outside. Say which ones
        // instead: nothing the organiser laid out disappears without a word.
        $capacity = $this->boardColumns * $this->boardRows;
        $outside = $tab->items->where('slot', '>=', $capacity);

        if ($outside->isNotEmpty()) {
            $this->addError('boardColumns', "Svuota prima {$outside->count()} caselle: con questa dimensione resterebbero fuori dalla scheda.");

            return;
        }

        $tab->update([
            'name' => $this->boardName,
            'description' => $this->boardDescription ?: null,
            'columns' => $this->boardColumns,
            'rows' => $this->boardRows,
        ]);

        $this->closeBoardForm();
        $this->refreshBoards();
    }

    /**
     * Show or hide the selected board on this station.
     *
     * "No selection stored" means "all of them", so hiding the first board has
     * to write the remaining ones down, and a station that ends up showing every
     * board goes back to storing nothing: that way a board created next week
     * appears here too, unless someone deliberately restricted this station.
     */
    public function openStationBoards(): void
    {
        $this->showStationBoards = true;
    }

    public function closeStationBoards(): void
    {
        $this->showStationBoards = false;
    }

    /**
     * Show or hide a board on this station. The complete "Tutto" tab has no id
     * here and cannot be hidden: it is what guarantees nothing ever becomes
     * unreachable through a configuration mistake.
     */
    public function toggleBoardHere(int $tabId): void
    {
        $this->writeStationLayout(
            $this->stationLayout->map(fn (array $entry): array => $entry['tab']?->id === $tabId
                ? [...$entry, 'visible' => ! $entry['visible']]
                : $entry),
        );
    }

    public function moveBoardHereUp(?int $tabId = null): void
    {
        $this->moveBoardHere($tabId, -1);
    }

    public function moveBoardHereDown(?int $tabId = null): void
    {
        $this->moveBoardHere($tabId, 1);
    }

    /**
     * Swap an entry of this station's bar with its neighbour. Moving one to the
     * front is also how a station is told what to open on.
     */
    protected function moveBoardHere(?int $tabId, int $direction): void
    {
        $entries = $this->stationLayout->all();
        $index = null;

        foreach ($entries as $position => $entry) {
            if ($entry['tab']?->id === $tabId) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= count($entries)) {
            return;
        }

        [$entries[$index], $entries[$target]] = [$entries[$target], $entries[$index]];

        $this->writeStationLayout(collect($entries));
    }

    /**
     * Store this station's bar as it now stands. The whole list is rewritten
     * every time: positions stay dense and the unique index can never trip.
     *
     * @param  Collection<int, array{tab: ?MenuTab, visible: bool}>  $entries
     */
    protected function writeStationLayout(Collection $entries): void
    {
        $register = $this->cashRegister;

        if (! $this->configuringBoard || $register === null) {
            return;
        }

        DB::transaction(function () use ($register, $entries): void {
            $register->boards()->delete();

            foreach ($entries->values() as $position => $entry) {
                $register->boards()->create([
                    'menu_tab_id' => $entry['tab']?->id,
                    'position' => $position,
                    'visible' => $entry['visible'],
                ]);
            }
        });

        $this->refreshBoards();
    }

    /**
     * Ask before dropping a board, stepping out of the board form so the two
     * dialogs never stack. Cancelling puts the organiser back where they were.
     */
    public function openDeleteBoard(): void
    {
        if ($this->selectedTab !== null) {
            $this->showBoardForm = false;
            $this->showDeleteBoard = true;
        }
    }

    public function cancelDeleteBoard(): void
    {
        $this->showDeleteBoard = false;
        $this->showBoardForm = true;
    }

    public function deleteBoard(): void
    {
        $this->selectedTab?->delete();
        $this->selectedTabId = null;
        $this->resetBoardEditing();
        $this->refreshBoards();
    }

    /**
     * Foods that can go on the board being laid out. Every active food, not just
     * tonight's: a board is built once for the whole sagra. Each is flagged with
     * whether it is already on this board and whether it is on no board at all,
     * so the organiser notices the ones no cashier would ever see.
     *
     * @return Collection<int, array{food: Food, placed: bool, orphan: bool}>
     */
    #[Computed]
    public function placeableFoods(): Collection
    {
        $tab = $this->selectedTab;

        if ($tab === null) {
            return collect();
        }

        $placedHere = $tab->items->pluck('food_id')->all();
        $placedAnywhere = MenuTabItem::query()->distinct()->pluck('food_id')->all();

        return Food::query()
            ->active()
            ->when($this->keySearch !== '', fn ($query) => $query->whereLike('name', "%{$this->keySearch}%", caseSensitive: false))
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Food $food): array => [
                'food' => $food,
                'placed' => in_array($food->id, $placedHere, true),
                'orphan' => ! in_array($food->id, $placedAnywhere, true),
            ]);
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
        return ($this->covers ?? 0) * $this->coverCharge;
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

        // Each station opens on the first board its own bar shows.
        $this->selectedTabId = $this->openingTabId();
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

    /**
     * The service choice as it reads on the button that opens it.
     */
    #[Computed]
    public function serviceLabel(): string
    {
        return match ($this->serviceType) {
            ServiceType::TableService->value => 'Tavolo '.$this->tableNumber,
            ServiceType::Pickup->value => 'Ritiro',
            default => 'Da scegliere',
        };
    }

    public function openService(): void
    {
        // A chosen table is offered back for correction; a pickup has no number
        // to correct, so the keypad starts empty.
        $this->tableInput = $this->serviceType === ServiceType::TableService->value ? (string) $this->tableNumber : '';
        $this->showService = true;
    }

    public function closeService(): void
    {
        $this->showService = false;
    }

    /**
     * Takes what the keypad sends as the sale's table, zero included. The pad
     * itself runs in the browser, so that pressing four digits does not cost
     * four renders of the whole till: this is where what it sends is checked.
     * Anything that is not digits is not a table, an empty pad is no answer at
     * all, and a longer number is cut to what the field holds.
     */
    public function chooseTable(string $input): void
    {
        $digits = $this->digitsOf($input, self::TABLE_DIGITS);

        if ($digits === '') {
            return;
        }

        $this->serviceType = ServiceType::TableService->value;
        $this->tableNumber = (int) $digits;
        $this->tableInput = $digits;
        $this->showService = false;

        // A table has just been laid: how many people sit at it is the next thing
        // to say, so the keypad for it opens by itself. Correcting a table
        // number later leaves the covers alone - they are already chosen.
        if ($this->covers === null) {
            $this->openCovers();
        }
    }

    /**
     * Takes the sale as a pickup: no table, and the documents say Ritiro. There
     * is no table to lay either, so the covers are settled at nobody with it -
     * a choice made, not a blank, and one the cashier can still change.
     */
    public function choosePickup(): void
    {
        $this->serviceType = ServiceType::Pickup->value;
        $this->tableNumber = null;
        $this->tableInput = '';
        $this->covers = 0;
        $this->coversInput = '';
        $this->showService = false;
    }

    /**
     * The covers as they read on the button that opens them.
     */
    #[Computed]
    public function coversLabel(): string
    {
        return $this->covers === null ? 'Da scegliere' : (string) $this->covers;
    }

    public function openCovers(): void
    {
        // Whatever was chosen is offered back for correction, zero included: it
        // is a number like the others, and the next digit pressed replaces it.
        $this->coversInput = $this->covers !== null ? (string) $this->covers : '';
        $this->showCovers = true;
    }

    public function closeCovers(): void
    {
        $this->showCovers = false;
    }

    /**
     * Takes what the keypad sends as the sale's covers, zero included: an order
     * laid for nobody is answered with a 0, not with a key of its own. Checked
     * like the table's, and for the same reason.
     */
    public function chooseCovers(string $input): void
    {
        $digits = $this->digitsOf($input, self::COVERS_DIGITS);

        if ($digits === '') {
            return;
        }

        $this->covers = (int) $digits;
        $this->coversInput = $digits;
        $this->showCovers = false;
    }

    /**
     * The digits of what a keypad sent, at most $max of them. Everything else is
     * dropped: the pad in the browser only ever sends digits, so anything else
     * arriving here was not typed by a cashier.
     */
    protected function digitsOf(string $input, int $max): string
    {
        return substr(preg_replace('/\D/', '', $input) ?? '', 0, $max);
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

    /**
     * Takes one portion off the line, and asks before the last one: a row that
     * vanishes under a finger still pressing lets the next press land on the row
     * that has slid up in its place, which is how a second line used to lose a
     * portion nobody touched. The confirmation stops the run and leaves the
     * quantity at 1 if the answer is no.
     */
    public function decrementLine(string $key): void
    {
        if ($this->paymentInProgress() || ! isset($this->cart[$key])) {
            return;
        }

        if ($this->cart[$key]['quantity'] <= 1) {
            $this->removingKey = $key;

            return;
        }

        $this->cart[$key]['quantity']--;
    }

    /**
     * The cart line whose removal is being confirmed, if any.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function removingLine(): ?array
    {
        return $this->removingKey !== null ? ($this->cart[$this->removingKey] ?? null) : null;
    }

    public function cancelRemoveLine(): void
    {
        $this->removingKey = null;
    }

    public function confirmRemoveLine(): void
    {
        if ($this->removingKey !== null && ! $this->paymentInProgress()) {
            unset($this->cart[$this->removingKey]);
        }

        $this->removingKey = null;
    }

    /**
     * Whether the till holds anything belonging to a customer: lines, or the
     * details taken before them. A table chosen for somebody who then walked
     * away stays on the till, and a chosen value raises no warning because it
     * is a choice - so the next order would leave for the wrong table with
     * nothing on screen to say so. Hence the reset is offered for the details
     * alone, not only for a cart with lines in it.
     */
    #[Computed]
    public function orderStarted(): bool
    {
        return $this->cart !== []
            || $this->serviceType !== null
            || $this->covers !== null
            || $this->discountType !== null
            || trim((string) $this->customerName) !== '';
    }

    public function openClearCart(): void
    {
        if ($this->paymentInProgress()) {
            return;
        }

        if ($this->orderStarted) {
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
        $this->reset('cart', 'serviceType', 'tableNumber', 'tableInput', 'customerName', 'covers', 'coversInput', 'frozenCoverCharge', 'frozenDiscountAppliesToCover', 'discountType', 'discountValue', 'showClearCart', 'removingKey');
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
     * A meal given away, in one press: the volunteers eat every evening and
     * their orders go through the till like everybody else's, so a full discount
     * is a preset rather than something to type. It closes the dialog as Applica
     * does, and the sale then takes the till's usual route for an order that
     * costs nothing - one button, and a confirmation before it leaves.
     */
    public function applyFullDiscount(): void
    {
        $this->discountType = DiscountType::Percentage->value;
        $this->discountValue = '100';
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

        if ($this->serviceType === null) {
            return 'Scegli il tavolo o il ritiro prima di incassare.';
        }

        if ($this->covers === null) {
            return 'Scegli i coperti prima di incassare.';
        }

        return null;
    }

    /**
     * Guards a payment against a sale nobody has said where to send, or for how
     * many. Rather than report the missing step, it opens it: the cashier lands
     * on the keypad that is still empty, and the caller stops.
     */
    protected function requireOrderDetails(): bool
    {
        if ($this->serviceType === null) {
            $this->openService();

            return false;
        }

        if ($this->covers === null) {
            $this->openCovers();

            return false;
        }

        return true;
    }

    /**
     * An order a discount has covered entirely. There is no tender to choose,
     * so there is one button and one confirmation: nothing is taken, and the
     * cash drawer stays shut.
     */
    public function startFreeOrder(): void
    {
        if ($this->cart === [] || $this->orderTotal !== 0) {
            return;
        }

        if (! $this->requireOrderDetails()) {
            return;
        }

        if ($blocker = $this->checkoutBlocker()) {
            $this->addError('checkout', $blocker);

            return;
        }

        // The stock is held like any other checkout: nothing was paid, but the
        // portions are just as gone.
        if (! $this->ensureReserved()) {
            return;
        }

        $this->showFreeOrder = true;
    }

    public function closeFreeOrder(): void
    {
        $this->showFreeOrder = false;
        $this->releaseReservation();
    }

    public function confirmFreeOrder(): void
    {
        if ($this->orderTotal !== 0) {
            return;
        }

        $this->finalize('none');
    }

    public function startCash(): void
    {
        if ($this->cart === []) {
            return;
        }

        if ($this->orderTotal === 0) {
            return;
        }

        if (! $this->requireOrderDetails()) {
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

        // The drawer opens with the payment screen, not with the receipt: the
        // cashier counts the change while the customer is still handing over
        // the money, instead of waiting for a confirmation she has not made
        // yet. It stays open through the exchange, which is why the receipt no
        // longer kicks it.
        $this->openCashDrawer();
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

        // Nothing to charge: this sale is confirmed, not paid.
        if ($this->orderTotal === 0) {
            return;
        }

        if (! $this->requireOrderDetails()) {
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
        $this->askTerminal();
    }

    /**
     * Sends the amount to this station's terminal, when it has one. A station
     * without a terminal, or one whose terminal is taken, simply falls back to
     * the manual flow the modal has always offered: the sale must never stop
     * because the integration cannot go ahead.
     */
    protected function askTerminal(): void
    {
        $this->cardTransactionId = null;
        $this->terminalBusyWith = null;
        $this->terminalFreeAgain = false;
        $this->unresolvedPayment = null;

        if ($this->cashRegister?->cardTerminal === null) {
            return;
        }

        // Nothing about the claim stops this: the terminal was taken by this
        // very station, so asking again would simply renew the hold and send a
        // second amount for a payment that may already have gone through.
        if (! $this->unresolvedAcknowledged && ($unresolved = $this->unresolvedCardPayment()) !== null) {
            $this->unresolvedPayment = [
                'amount' => $this->money($unresolved->amount_cents),
                'at' => $unresolved->created_at?->setTimezone(app(EventSettings::class)->timezone)->format('H:i') ?? '-',
            ];

            return;
        }

        $attempt = app(PaymentRunner::class)->start($this->cashRegister, $this->orderTotal);

        if ($attempt === null) {
            // Taken by another station. The card flow cannot start at all, and
            // the modal says so instead of falling back to a confirmation the
            // cashier has no way of making: whatever is happening on that
            // terminal is somebody else's customer.
            $this->terminalBusyWith = $this->cashRegister->cardTerminal->fresh()->busyRegisterName() ?? '-';

            return;
        }

        $this->cardTransactionId = $attempt->id;
        SendCardPaymentJob::dispatchFor($attempt);
    }

    /**
     * Watched while the card modal is stuck on a busy terminal. The moment the
     * other station gives it back, the amount goes out: the cashier already
     * asked for a card payment, and making her ask again would only give the
     * terminal away to whoever taps first.
     */
    public function watchTerminal(): void
    {
        if ($this->terminalBusyWith === null || ! $this->showCardModal) {
            return;
        }

        $terminal = $this->cashRegister?->cardTerminal?->fresh();
        $free = $terminal !== null && $terminal->isAvailableFor($this->cashRegister);

        // It can go back and forth: another station may take it again while
        // this one waits, and the modal has to say what is true now.
        if ($free && ! $this->terminalFreeAgain) {
            $this->dispatch('pos-notice', message: 'Terminale libero: prendilo e premi Riprova.');
        }

        $this->terminalFreeAgain = $free;
        $this->terminalBusyWith = $free
            ? $this->terminalBusyWith
            : ($terminal?->busyRegisterName() ?? $this->terminalBusyWith);
    }

    /**
     * The last payment on this station left hanging: no answer from the
     * terminal, and no order behind it. Recent ones only - a doubt from two
     * hours ago is a matter for the admin list, not for the cashier in front of
     * a queue.
     */
    protected function unresolvedCardPayment(): ?CardTransaction
    {
        return CardTransaction::query()
            ->where('cash_register_id', $this->cashRegisterId)
            ->where('status', CardTransactionStatus::Unknown)
            ->whereNull('order_id')
            ->where('created_at', '>', now()->subMinutes(30))
            ->latest()
            ->first();
    }

    /**
     * "I have looked at the terminal." Taken at her word for the rest of this
     * sale: she is the one who can see the screen, and a warning that cannot be
     * dismissed is a warning that gets learned around.
     */
    public function acknowledgeUnresolved(): void
    {
        $this->unresolvedAcknowledged = true;
        $this->askTerminal();
    }

    /** The attempt the modal is watching, if any. */
    #[Computed]
    public function cardTransaction(): ?CardTransaction
    {
        return $this->cardTransactionId !== null ? CardTransaction::find($this->cardTransactionId) : null;
    }

    /**
     * Watched by the modal while the customer is on the terminal. An approved
     * payment closes the sale on its own: the cashier has nothing left to
     * confirm, and asking her to would only add a way to get it wrong.
     */
    public function pollCardPayment(): void
    {
        unset($this->cardTransaction);

        if ($this->cardTransaction?->isApproved()) {
            $this->finalize('card');
        }
    }

    /**
     * Sends the amount again after a refusal or a failure - never after an
     * unanswered attempt, which has to be resolved with the terminal first.
     */
    public function retryCardPayment(): void
    {
        if ($this->cardTransaction?->needsAnswer() ?? false) {
            return;
        }

        $this->askTerminal();
    }

    public function closeCard(): void
    {
        // A payment under way is not something to walk away from: the customer
        // is at the terminal and the answer is about to arrive.
        if ($this->cardPaymentPending()) {
            return;
        }

        $this->releaseTerminalClaim();
        $this->showCardModal = false;
        $this->cardTransactionId = null;
        $this->terminalBusyWith = null;
        $this->terminalFreeAgain = false;
        $this->unresolvedPayment = null;
        $this->releaseReservation();
    }

    public function cardToCash(): void
    {
        if ($this->cardPaymentPending()) {
            return;
        }

        // Keep the reservation: the sale is still going, only the tender changes.
        $this->releaseTerminalClaim();
        $this->showCardModal = false;
        $this->cardTransactionId = null;
        $this->terminalBusyWith = null;
        $this->terminalFreeAgain = false;
        $this->unresolvedPayment = null;
        $this->startCash();
    }

    /**
     * The cashier says it went through. Available whatever the integration
     * thinks, because she is the one looking at the terminal - and marked as
     * hers, so a row that was closed by a person can always be told from one
     * the terminal closed.
     */
    public function confirmCard(): void
    {
        // Nothing to confirm: the terminal is taking someone else's payment, or
        // is still taking this one - a stale page in her hand must not be able
        // to close a sale over a customer who is mid-PIN.
        if ($this->terminalBusyWith !== null || $this->unresolvedPayment !== null || $this->cardPaymentPending()) {
            return;
        }

        $this->cardTransaction?->update([
            'status' => CardTransactionStatus::Approved,
            'manual' => true,
            'completed_at' => now(),
        ]);

        $this->finalize('card');
    }

    /**
     * Whether the terminal is still working on this station's payment.
     *
     * An attempt that has been open far longer than a payment can take does not
     * count: the worker died or never ran, and leaving the cashier on a waiting
     * screen that will never end is the worst thing this modal could do.
     */
    public function cardPaymentPending(): bool
    {
        $attempt = $this->cardTransaction;

        return $attempt !== null
            && $attempt->status === CardTransactionStatus::Pending
            && ! $attempt->isStuck();
    }

    /**
     * Gives the terminal back when the sale leaves the card flow. An attempt
     * with no answer keeps it: until someone finds out what happened, nobody
     * else may start a transaction on that terminal.
     */
    protected function releaseTerminalClaim(): void
    {
        $attempt = $this->cardTransaction;

        if ($attempt === null || $attempt->needsAnswer()) {
            return;
        }

        $this->cashRegister?->cardTerminal?->release($this->cashRegister);
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
                ServiceType::from($this->serviceType),
                $this->tableNumber,
                $this->customerName ?: null,
                PaymentMethod::from($method),
                $items,
                $this->discountType !== null ? DiscountType::from($this->discountType) : null,
                $this->discountValueForDomain(),
                (int) $this->covers,
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

        // Tie the payment attempt to the order it paid for: from here on the two
        // are read together, and a card transaction with no order is exactly the
        // thing someone must go and look at.
        $this->cardTransaction?->update(['order_id' => $order->id]);
        unset($this->cardTransaction);

        $this->placedOrderNumber = $order->number;
        $this->reset('cart', 'serviceType', 'tableNumber', 'tableInput', 'customerName', 'covers', 'coversInput', 'frozenCoverCharge', 'frozenDiscountAppliesToCover', 'discountType', 'discountValue', 'removingKey', 'showDiscount', 'showCashModal', 'showCardModal', 'showFreeOrder', 'cashReceivedCents', 'cashInput', 'reservationId', 'cardTransactionId', 'terminalBusyWith', 'terminalFreeAgain', 'unresolvedPayment', 'unresolvedAcknowledged', 'showSoldOut', 'soldOutItems', 'showReservationExpired');
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
     * How many more base portions of the food the tracked stock still allows,
     * after what the cart already takes: the scarcest of its tracked
     * ingredients decides. Null when none of them is tracked - that is "no
     * number to show", which is not the same as a zero.
     *
     * @param  array<int, int>  $consumption  ingredient id => units already in cart
     */
    protected function foodPortionsLeft(Food $food, array $consumption): ?int
    {
        $left = null;

        foreach ($food->ingredients as $ingredient) {
            // A dose of zero is an optional extra, not part of a base portion:
            // it limits nothing here.
            if ($ingredient->stock === null || $ingredient->pivot->quantity < 1) {
                continue;
            }

            $free = max(0, $ingredient->stock - ($consumption[$ingredient->id] ?? 0));
            $portions = intdiv($free, $ingredient->pivot->quantity);

            $left = $left === null ? $portions : min($left, $portions);
        }

        return $left;
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
