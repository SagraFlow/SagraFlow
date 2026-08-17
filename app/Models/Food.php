<?php

namespace App\Models;

use App\Models\Concerns\Activatable;
use App\Models\Concerns\NormalizesName;
use Database\Factories\FoodFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Food extends Model
{
    use Activatable;

    /** @use HasFactory<FoodFactory> */
    use HasFactory;

    use NormalizesName;

    protected $table = 'foods';

    protected $fillable = ['category_id', 'name', 'short_name', 'price', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'price' => 'integer',
        ];
    }

    /**
     * An emptied field is stored as null, not as an empty string, so "no
     * abbreviation" has one representation and the fallback below is the only
     * rule there is.
     */
    protected function shortName(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $value = preg_replace('/\s+/', ' ', trim((string) $value));

                return $value === '' ? null : $value;
            },
        );
    }

    /**
     * What the cashier reads on the key: the abbreviation when there is one,
     * the full name otherwise. Only the keys use it - cart, receipts, kitchen
     * tickets and the admin panel all stay on the full name, so what is said
     * out loud and what is printed never drift apart.
     */
    protected function keyName(): Attribute
    {
        return Attribute::get(fn (): string => $this->short_name ?? $this->name);
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
