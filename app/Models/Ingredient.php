<?php

namespace App\Models;

use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'surcharge', 'stock', 'available'];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'surcharge' => 'integer',
            'stock' => 'integer',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => preg_replace('/\s+/', ' ', trim($value)),
        );
    }

    public function scopeAvailable($query)
    {
        return $query->where('available', true);
    }
}
