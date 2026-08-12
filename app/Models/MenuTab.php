<?php

namespace App\Models;

use App\Models\Concerns\NormalizesName;
use Database\Factories\MenuTabFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A board of till keys laid out by the organiser: a fixed grid of cells, each
 * either holding a food or empty. Positions never move, so cashiers build the
 * muscle memory that makes them fast, and the menu of the evening only decides
 * which keys are lit, never where they are.
 */
class MenuTab extends Model
{
    /** @use HasFactory<MenuTabFactory> */
    use HasFactory;

    use NormalizesName;

    protected $fillable = ['name', 'description', 'columns', 'rows'];

    protected function casts(): array
    {
        return [
            'columns' => 'integer',
            'rows' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuTabItem::class);
    }

    /**
     * Number of cells on the board.
     */
    public function capacity(): int
    {
        return $this->columns * $this->rows;
    }

    /**
     * Creation order, which is only ever the starting point for a station's bar:
     * the order that matters is arranged per station.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('id');
    }
}
