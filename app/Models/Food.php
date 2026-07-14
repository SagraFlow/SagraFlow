<?php

namespace App\Models;

use Database\Factories\FoodFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Food extends Model
{
    /** @use HasFactory<FoodFactory> */
    use HasFactory;

    protected $fillable = ['category_id', 'name', 'price', 'available'];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'price' => 'integer',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => preg_replace('/\s+/', ' ', trim($value)),
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class)
            ->withPivot('quantity', 'min_quantity', 'max_quantity');
    }

    public function scopeAvailable($query)
    {
        return $query->where('available', true);
    }
}
