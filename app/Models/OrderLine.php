<?php

namespace App\Models;

use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected $fillable = ['order_id', 'food_id', 'food_name', 'unit_price', 'quantity', 'line_total', 'note'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(OrderLineIngredient::class);
    }

    /**
     * Per-portion surcharge coming from the ingredient customizations (in cents).
     */
    public function unitSurcharge(): int
    {
        return $this->ingredients->sum(fn (OrderLineIngredient $ingredient): int => $ingredient->surchargeTotal());
    }

    /**
     * Total for the whole line, surcharges included (in cents).
     */
    public function computeTotal(): int
    {
        return ($this->unit_price + $this->unitSurcharge()) * $this->quantity;
    }
}
