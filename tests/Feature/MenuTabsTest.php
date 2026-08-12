<?php

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\MenuTab;
use App\Models\MenuTabItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function openTillDay(): EventDay
{
    $day = EventDay::factory()->create();
    $day->open(User::factory()->create());

    return $day;
}

/**
 * A register on an open day, plus a food per given name in one category.
 *
 * @param  array<int, string>  $foodNames
 * @return array{0: CashRegister, 1: Collection<string, Food>}
 */
function tillWithFoods(array $foodNames): array
{
    openTillDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();

    $foods = collect($foodNames)->mapWithKeys(fn (string $name): array => [
        $name => Food::factory()->create(['category_id' => $category->id, 'name' => $name]),
    ]);

    return [$register, $foods];
}

it('hides the tab bar entirely when no board is laid out', function () {
    [$register] = tillWithFoods(['Panino']);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertDontSee('Tutto')
        ->assertSee('Panino');
});

it('shows the tab bar once a board exists', function () {
    [$register] = tillWithFoods(['Panino']);
    MenuTab::factory()->create(['name' => 'Griglia']);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Tutto')
        ->assertSee('Griglia');
});

it('places each food on its own cell and leaves the others empty', function () {
    [$register, $foods] = tillWithFoods(['Panino', 'Piadina']);
    $tab = MenuTab::factory()->board(3, 2)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Piadina']->id, 'slot' => 4]);

    $board = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->get('board');

    expect($board)->toHaveCount(6)                      // 3 columns x 2 rows
        ->and($board[0]['food']->name)->toBe('Panino')
        ->and($board[4]['food']->name)->toBe('Piadina')
        ->and(array_filter($board))->toHaveCount(2);    // every other cell empty
});

it('keeps the keys in place on an evening a food is off the menu', function () {
    [$register, $foods] = tillWithFoods(['Panino', 'Piadina', 'Dolce']);
    $tab = MenuTab::factory()->board(3, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Piadina']->id, 'slot' => 1]);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Dolce']->id, 'slot' => 2]);

    // Piadina is served on another evening only.
    $foods['Piadina']->eventDays()->attach(EventDay::factory()->create()->id);

    $board = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->get('board');

    // The hole stays a hole: Dolce must NOT slide up into cell 1, or the cashier
    // would find a different board on Friday than on Saturday.
    expect($board[0]['food']->name)->toBe('Panino')
        ->and($board[1])->toBeNull()
        ->and($board[2]['food']->name)->toBe('Dolce');
});

it('ignores items left outside a board that was shrunk', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 9]);

    $board = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->get('board');

    expect($board)->toHaveCount(2)
        ->and(array_filter($board))->toBeEmpty();
});

it('sells a food tapped on a board', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 3]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('addFood', $foods['Panino']->id);

    expect($component->get('cart'))->toHaveCount(1);
});

it('keeps a sold-out key in its cell instead of emptying it', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $salsiccia = Ingredient::factory()->tracked(0)->create();
    $foods['Panino']->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);
    $tab = MenuTab::factory()->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->assertSee('Esaurito');

    // Running out during service is not the same as being off the menu: the key
    // stays where the hand expects it, greyed out.
    $board = $component->get('board');
    expect($board[0]['food']->name)->toBe('Panino')
        ->and($board[0]['available'])->toBeFalse();
});

it('falls back to the complete tab when the selected board disappears', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->create(['name' => 'Griglia']);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id);

    $tab->delete();

    // Never leave the cashier staring at an empty screen mid-service.
    $component->call('$refresh')->assertSee('Panino');
    expect($component->get('board'))->toBeEmpty();
});

it('starts a station bar in the order the boards were created', function () {
    [$register] = tillWithFoods(['Panino']);
    MenuTab::factory()->create(['name' => 'Griglia']);
    MenuTab::factory()->create(['name' => 'Dolci']);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSeeInOrder(['Tutto', 'Griglia', 'Dolci']);
});

it('places a food on the tapped cell while laying out a board', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 2)->create();

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('tapCell', 2)
        ->assertSet('showKeyPicker', true)
        ->call('placeKey', $foods['Panino']->id)
        ->assertSet('showKeyPicker', false);

    expect(MenuTabItem::where('menu_tab_id', $tab->id)->first()->slot)->toBe(2)
        ->and($component->get('board')[2]['food']->name)->toBe('Panino');
});

it('refuses the same food twice on one board', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 2)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('tapCell', 1)
        ->call('placeKey', $foods['Panino']->id)
        ->assertDispatched('pos-notice');

    expect(MenuTabItem::where('menu_tab_id', $tab->id)->count())->toBe(1);
});

it('moves a key to an empty cell with two taps', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 2)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('tapCell', 0)          // pick it up
        ->call('startMove')
        ->assertSet('movingSlot', 0)
        ->call('tapCell', 3)          // drop it
        ->assertSet('movingSlot', null);

    $board = $component->get('board');
    expect($board[0])->toBeNull()
        ->and($board[3]['food']->name)->toBe('Panino');
});

it('swaps two keys when the destination cell is taken', function () {
    [$register, $foods] = tillWithFoods(['Panino', 'Piadina']);
    $tab = MenuTab::factory()->board(2, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Piadina']->id, 'slot' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('tapCell', 0)
        ->call('startMove')
        ->call('tapCell', 1);

    $board = $component->get('board');
    expect($board[0]['food']->name)->toBe('Piadina')
        ->and($board[1]['food']->name)->toBe('Panino');
});

it('removes a key from its cell', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('tapCell', 1)
        ->assertSet('showKeyPicker', false)   // taken cell: actions, not the picker
        ->call('removeKey');

    expect(MenuTabItem::count())->toBe(0)
        ->and($component->get('board')[1])->toBeNull();
});

it('does not sell while the board is being laid out', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('tapCell', 0);

    expect($component->get('cart'))->toBeEmpty();
});

it('refuses to lay out boards with an order in progress', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    MenuTab::factory()->create();

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $foods['Panino']->id)
        ->call('enterBoardConfig')
        ->assertSet('configuringBoard', false)
        ->assertDispatched('pos-notice');
});

it('creates a board from the till', function () {
    [$register] = tillWithFoods(['Panino']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('openBoardForm', true)
        ->set('boardName', 'Griglia')
        ->set('boardColumns', 4)
        ->set('boardRows', 3)
        ->call('saveBoard')
        ->assertHasNoErrors()
        ->assertSet('showBoardForm', false);

    expect(MenuTab::count())->toBe(1)
        ->and($component->get('board'))->toHaveCount(12);
});

it('refuses to shrink a board over occupied cells', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(4, 3)->create(['name' => 'Griglia']);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 11]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('openBoardForm')
        ->set('boardColumns', 2)
        ->set('boardRows', 2)
        ->call('saveBoard')
        ->assertHasErrors('boardColumns');

    // Nothing the organiser laid out vanishes without a word.
    expect($tab->fresh()->columns)->toBe(4)
        ->and(MenuTabItem::count())->toBe(1);
});

it('shows a key placed for another evening while laying out the board', function () {
    [$register, $foods] = tillWithFoods(['Panino', 'Polenta']);
    $tab = MenuTab::factory()->board(2, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Polenta']->id, 'slot' => 1]);

    // Polenta is only served on another evening of the sagra.
    $foods['Polenta']->eventDays()->attach(EventDay::factory()->create()->id);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id);

    // Selling: the cell is a hole, exactly as on any other evening.
    expect($component->get('board')[1])->toBeNull();

    // Laying out: the key shows plainly, like any other. Otherwise the organiser
    // places it, watches it vanish, and finds a cell that answers to taps but
    // looks empty.
    $component->call('enterBoardConfig');

    $board = $component->get('board');
    expect($board[1]['food']->name)->toBe('Polenta')
        ->and($board[1]['available'])->toBeTrue()
        ->and($board[0]['available'])->toBeTrue();

    // And it goes back to being a hole the moment config mode is left.
    expect($component->call('exitBoardConfig')->get('board')[1])->toBeNull();
});

it('shows a key whose food was deactivated while laying out the board', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);
    $foods['Panino']->update(['active' => false]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig');

    expect($component->get('board')[0]['food']->name)->toBe('Panino')
        ->and($component->get('board')[0]['available'])->toBeTrue();

    // Selling, it is gone: the organiser sees the layout, the cashier the menu.
    expect($component->call('exitBoardConfig')->get('board')[0])->toBeNull();
});

it('does not shrink the board when entering config mode', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->board(2, 2)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->assertSee('Nome Cliente');          // the cart holds the right column

    // Config controls take over the right column instead of stacking above the
    // grid: the menu column keeps the exact size it has in service, so keys are
    // laid out at the size they will be sold from.
    $component->call('enterBoardConfig')
        ->assertSee('Tocca una casella vuota')
        ->assertDontSee('Nome Cliente');

    $component->call('exitBoardConfig')
        ->assertSee('Nome Cliente')
        ->assertDontSee('Tocca una casella vuota');
});

it('asks before deleting a board, in a modal like everywhere else', function () {
    [$register, $foods] = tillWithFoods(['Panino']);
    $tab = MenuTab::factory()->create(['name' => 'Griglia']);
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $foods['Panino']->id, 'slot' => 0]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('selectTab', $tab->id)
        ->call('enterBoardConfig')
        ->call('openBoardForm')
        ->call('openDeleteBoard')
        ->assertSet('showDeleteBoard', true)
        ->assertSet('showBoardForm', false)     // the two dialogs never stack
        ->assertSee('Vuoi eliminare la scheda');

    // Backing out returns to the form, and the board is untouched.
    $component->call('cancelDeleteBoard')
        ->assertSet('showDeleteBoard', false)
        ->assertSet('showBoardForm', true);
    expect(MenuTab::count())->toBe(1);

    $component->call('openDeleteBoard')->call('deleteBoard')
        ->assertSet('showDeleteBoard', false)
        ->assertSet('selectedTabId', null);

    expect(MenuTab::count())->toBe(0)
        ->and(MenuTabItem::count())->toBe(0);   // its cells go with it
});

it('lays out boards with no day open', function () {
    // No open day: this is the afternoon before the sagra, which is exactly when
    // boards get laid out. The station, though, is always chosen first.
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Panino']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Nessuna giornata aperta')
        ->call('enterBoardConfig')
        ->assertSet('configuringBoard', true)
        ->call('openBoardForm', true)
        ->set('boardName', 'Griglia')
        ->set('boardColumns', 2)
        ->set('boardRows', 2)
        ->call('saveBoard')
        ->assertHasNoErrors()
        ->call('tapCell', 1)
        ->call('placeKey', $food->id);

    expect(MenuTabItem::where('slot', 1)->count())->toBe(1)
        ->and($component->get('board')[1]['food']->name)->toBe('Panino');

    // Done: back where it started, still nothing to sell.
    $component->call('exitBoardConfig')
        ->assertSet('configuringBoard', false)
        ->assertSee('Nessuna giornata aperta');
});

it('shows every placed key with no day open, price and all', function () {
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Polenta', 'price' => 700]);
    $food->eventDays()->attach(EventDay::factory()->create()->id);   // bound to one evening
    $tab = MenuTab::factory()->board(2, 1)->create();
    MenuTabItem::create(['menu_tab_id' => $tab->id, 'food_id' => $food->id, 'slot' => 0]);

    // Laying out is not selling: the board reads as if the whole menu were on.
    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('selectTab', $tab->id)
        ->assertSee('Polenta')
        ->assertSee('7,00');

    expect($component->get('board')[0]['available'])->toBeTrue();
});

it('hides the selling controls while laying out boards', function () {
    [$register] = tillWithFoods(['Panino']);
    MenuTab::factory()->create(['name' => 'Griglia']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Modifica schede')
        ->assertSee('Cambia postazione cassa')
        ->assertSee('Apri cassetto');

    // Config mode is left with "Fine". Changing register from the menu would
    // clear it without ever showing the picker, which config mode bypasses.
    $component->call('enterBoardConfig')
        ->assertDontSee('Modifica schede')
        ->assertDontSee('Cambia postazione cassa')
        ->assertDontSee('Apri cassetto');

    $component->call('exitBoardConfig')
        ->assertSee('Modifica schede')
        ->assertSee('Cambia postazione cassa');
});

it('shows every board on every station until one is restricted', function () {
    [$registerA] = tillWithFoods(['Panino']);
    $registerB = CashRegister::factory()->create(['name' => 'Cassa Bar']);
    MenuTab::factory()->create(['name' => 'Griglia']);
    $bar = MenuTab::factory()->create(['name' => 'Bar']);

    // Nothing configured: both stations see both boards.
    Livewire::test('pages::pos')->call('selectRegister', $registerA->id)->assertSee('Griglia')->assertSee('Bar');
    Livewire::test('pages::pos')->call('selectRegister', $registerB->id)->assertSee('Griglia')->assertSee('Bar');

    // Hide Griglia on the bar station, standing at the bar station.
    Livewire::test('pages::pos')
        ->call('selectRegister', $registerB->id)
        ->call('enterBoardConfig')
        ->call('toggleBoardHere', MenuTab::where('name', 'Griglia')->first()->id)
        ->call('exitBoardConfig')
        ->assertDontSee('Griglia')
        ->assertSee('Bar');

    // The other station is untouched.
    Livewire::test('pages::pos')->call('selectRegister', $registerA->id)->assertSee('Griglia');
});

it('goes back to showing everything when nothing is left excluded', function () {
    [$register] = tillWithFoods(['Panino']);
    $griglia = MenuTab::factory()->create(['name' => 'Griglia']);
    MenuTab::factory()->create(['name' => 'Bar']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('toggleBoardHere', $griglia->id);

    expect($register->fresh()->boards()->where('visible', false)->count())->toBe(1);

    // Putting it back clears the restriction instead of listing every board, so
    // a board created next week shows up here too.
    $component->call('toggleBoardHere', $griglia->id);
    expect($register->fresh()->boards()->where('visible', false)->count())->toBe(0);

    $component->call('exitBoardConfig')->assertSee('Griglia')->assertSee('Bar');
});

it('opens each station on the board it was set to open on', function () {
    [$register] = tillWithFoods(['Panino']);
    $bar = MenuTab::factory()->create(['name' => 'Bar']);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('moveBoardHereUp', $bar->id)   // first in the bar is what opens
        ->call('moveBoardHereUp', $bar->id)
        ->call('exitBoardConfig');

    // A fresh load of the till lands straight on that board.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSet('selectedTabId', $bar->id);
});

it('hands the opening slot to the next entry when the first is hidden', function () {
    [$register] = tillWithFoods(['Panino']);
    $bar = MenuTab::factory()->create(['name' => 'Bar']);
    $griglia = MenuTab::factory()->create(['name' => 'Griglia']);

    // Bar to the front, so the station opens on it.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('moveBoardHereUp', $bar->id)
        ->call('moveBoardHereUp', $bar->id);

    Livewire::test('pages::pos')->call('selectRegister', $register->id)->assertSet('selectedTabId', $bar->id);

    // Hiding it hands the slot to whatever comes next. Nothing stored can point
    // at a hidden board, because nothing is stored: the first shown one opens.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('toggleBoardHere', $bar->id);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSet('selectedTabId', null);   // Tutto, which sits right after it

    expect($griglia->fresh())->not->toBeNull();
});

it('keeps the bar settings in step with what was just changed', function () {
    [$register] = tillWithFoods(['Panino']);
    $griglia = MenuTab::factory()->create(['name' => 'Griglia']);
    $bar = MenuTab::factory()->create(['name' => 'Bar']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('openStationBoards')
        ->call('toggleBoardHere', $bar->id)
        ->assertSee('Nascosta');          // the row updates on the spot

    // Closing and reopening reads it back from the database, unchanged.
    $component->call('closeStationBoards')->call('openStationBoards')->assertSee('Nascosta');

    expect($register->fresh()->boards()->where('menu_tab_id', $bar->id)->first()->visible)->toBeFalse()
        ->and($register->fresh()->boards()->where('menu_tab_id', $griglia->id)->first()->visible)->toBeTrue();
});

it('never lets the complete tab be hidden', function () {
    [$register] = tillWithFoods(['Panino']);
    $bar = MenuTab::factory()->create(['name' => 'Bar']);

    // Move it out of the way, then try to switch it off: it is the safety net
    // that guarantees every food stays reachable, so it only ever moves.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('moveBoardHereUp', $bar->id)
        ->call('toggleBoardHere', 0);           // no board has id 0: the complete tab

    $component = Livewire::test('pages::pos')->call('selectRegister', $register->id);

    expect($component->get('barEntries')->pluck('tab')->contains(null))->toBeTrue()
        ->and($component->get('selectedTabId'))->toBe($bar->id);
});

it('tells apart two boards sharing a name by their description', function () {
    [$register] = tillWithFoods(['Panino']);
    MenuTab::factory()->create(['name' => 'Bar', 'description' => 'cassa del bar']);
    MenuTab::factory()->create(['name' => 'Bar', 'description' => 'cassa generale']);

    // The description exists for whoever arranges the boards, so it lives in the
    // list and never on the till itself.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('openStationBoards')
        ->assertSee('cassa del bar')
        ->assertSee('cassa generale')
        ->call('closeStationBoards')
        ->call('exitBoardConfig')
        ->assertDontSee('cassa del bar');
});

it('saves a description with the board', function () {
    [$register] = tillWithFoods(['Panino']);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('openBoardForm', true)
        ->set('boardName', 'Bar')
        ->set('boardDescription', 'solo per la cassa del bar')
        ->call('saveBoard')
        ->assertHasNoErrors();

    expect(MenuTab::first()->description)->toBe('solo per la cassa del bar');
});

it('puts a board hidden here out of reach on this station', function () {
    [$register] = tillWithFoods(['Panino']);
    $bar = MenuTab::factory()->board(2, 1)->create(['name' => 'Bar']);
    MenuTab::factory()->create(['name' => 'Griglia']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('selectTab', $bar->id)
        ->call('toggleBoardHere', $bar->id)
        ->assertDontSee('Bar');   // out of the bar even while arranging

    // Hidden means hidden: it cannot be worked on from here either, so the
    // screen falls back to the complete tab. Show it again, or arrange it from
    // a station that does show it.
    expect($component->get('selectedTab'))->toBeNull();

    $component->call('selectTab', $bar->id);
    expect($component->get('selectedTab'))->toBeNull();

    $component->call('exitBoardConfig');
    expect($component->get('barEntries')->pluck('tab')->filter()->pluck('name')->all())->toBe(['Griglia']);
});

it('offers the new board button only in the boards bar', function () {
    [$register] = tillWithFoods(['Panino']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertDontSee('Nuova scheda')       // never while selling
        ->call('enterBoardConfig')
        ->assertSee('Nuova scheda');

    // The category row inside "Tutto" is not a bar of boards: nothing can be
    // created there, so the button must not turn up next to the categories.
    expect(substr_count($component->html(), 'openBoardForm(true)'))->toBe(1);
});

it('stays on the board behind the form while a new one is being created', function () {
    [$register] = tillWithFoods(['Panino']);
    $griglia = MenuTab::factory()->create(['name' => 'Griglia']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('selectTab', $griglia->id)
        ->call('openBoardForm', true)
        ->assertSet('creatingBoard', true)
        ->assertSet('selectedTabId', $griglia->id)   // the screen behind stays put
        ->assertSee('Nuova scheda');

    // Backing out leaves everything where it was.
    $component->call('closeBoardForm')
        ->assertSet('selectedTabId', $griglia->id)
        ->assertSet('creatingBoard', false);

    // Saving switches to the board just made, which is where the work continues.
    $component->call('openBoardForm', true)
        ->set('boardName', 'Bar')
        ->call('saveBoard')
        ->assertHasNoErrors();

    expect($component->get('selectedTabId'))->toBe(MenuTab::where('name', 'Bar')->first()->id)
        ->and($griglia->fresh()->name)->toBe('Griglia');   // the one behind was not renamed
});

it('describes only the complete tab as all the food', function () {
    [$register] = tillWithFoods(['Panino']);
    MenuTab::factory()->create(['name' => 'Griglia']);                                  // no description
    MenuTab::factory()->create(['name' => 'Bar', 'description' => 'cassa del bar']);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('enterBoardConfig')
        ->call('openStationBoards')
        ->assertSee('cassa del bar');

    // A board with no description says nothing, rather than borrowing the line
    // that belongs to the complete tab.
    expect(substr_count($component->html(), 'Tutte le pietanze'))->toBe(1);
});

it('asks which station this tablet is before anything else, config included', function () {
    // Boards are laid out for a station, so there is always one to lay them out
    // for: config mode does not get to skip the question.
    $register = CashRegister::factory()->create();

    Livewire::test('pages::pos')
        ->assertSee('Seleziona la cassa')
        ->call('enterBoardConfig')
        ->assertSee('Seleziona la cassa')
        ->assertDontSee('Configurazione schede')
        ->call('selectRegister', $register->id)
        ->assertSee('Configurazione schede');
});
