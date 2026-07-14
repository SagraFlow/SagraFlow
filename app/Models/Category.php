<?php

namespace App\Models;

use App\Models\Concerns\NormalizesName;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use NormalizesName;

    protected $fillable = ['name', 'position', 'available'];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if ($category->position === null) {
                $category->position = (static::max('position') ?? 0) + 1;
            }
        });
    }

    public function foods(): HasMany
    {
        return $this->hasMany(Food::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('available', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
