<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\MenuTab;
use App\Models\MenuTabItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuTabItem>
 */
class MenuTabItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_tab_id' => MenuTab::factory(),
            'food_id' => Food::factory(),
            'slot' => 0,
        ];
    }
}
