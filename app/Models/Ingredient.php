<?php

namespace App\Models;

use App\Models\Concerns\Activatable;
use App\Models\Concerns\NormalizesName;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    use Activatable;

    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    use NormalizesName;

    protected $fillable = ['name', 'surcharge', 'stock', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'surcharge' => 'integer',
            'stock' => 'integer',
        ];
    }

    public function foods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class)
            ->withPivot('quantity', 'min_quantity', 'max_quantity');
    }

    /**
     * Atomically consumes the given number of stock units. Untracked
     * ingredients (null stock) are unlimited and always succeed. For tracked
     * ingredients the decrement is conditional on there being enough stock, so
     * concurrent sales can never drive the stock negative: returns false (and
     * changes nothing) when the units are not available.
     */
    public function consume(int $units): bool
    {
        if ($this->stock === null || $units <= 0) {
            return true;
        }

        return static::whereKey($this->getKey())
            ->whereNotNull('stock')
            ->where('stock', '>=', $units)
            ->decrement('stock', $units) > 0;
    }
}
