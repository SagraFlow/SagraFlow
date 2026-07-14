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

    protected $table = 'foods';

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

    public function eventDays(): BelongsToMany
    {
        return $this->belongsToMany(EventDay::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('available', true);
    }

    /**
     * Foods sellable on the given operational day: those with no day
     * restriction are always sellable, restricted ones only on their days.
     * A null day matches only unrestricted foods.
     */
    public function scopeAvailableOn($query, ?EventDay $day)
    {
        return $query->where(function ($query) use ($day) {
            $query->whereDoesntHave('eventDays');

            if ($day !== null) {
                $query->orWhereHas('eventDays', fn ($query) => $query->whereKey($day->id));
            }
        });
    }
}
