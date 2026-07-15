<?php

namespace App\Models;

use Database\Factories\OrderLineIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLineIngredient extends Model
{
    /** @use HasFactory<OrderLineIngredientFactory> */
    use HasFactory;

    protected $fillable = ['order_line_id', 'ingredient_id', 'ingredient_name', 'quantity', 'base_quantity', 'surcharge'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'base_quantity' => 'integer',
            'surcharge' => 'integer',
        ];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Surcharge charged for the units chosen above the base dose (in cents).
     */
    public function surchargeTotal(): int
    {
        return $this->surcharge * max(0, $this->quantity - $this->base_quantity);
    }
}
