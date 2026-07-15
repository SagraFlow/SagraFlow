<?php

namespace App\Models;

use App\Models\Concerns\NormalizesName;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
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

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
