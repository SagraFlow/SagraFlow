<?php

namespace App\Models;

use Database\Factories\MenuTabItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One food placed on one cell of a board.
 */
class MenuTabItem extends Model
{
    /** @use HasFactory<MenuTabItemFactory> */
    use HasFactory;

    protected $fillable = ['menu_tab_id', 'food_id', 'slot'];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
        ];
    }

    public function menuTab(): BelongsTo
    {
        return $this->belongsTo(MenuTab::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
