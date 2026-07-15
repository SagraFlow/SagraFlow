<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Exceptions\OrderException;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = ['event_day_id', 'cash_register_id', 'user_id', 'number', 'table_number', 'customer_name', 'covers', 'service_type', 'status', 'payment_method', 'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'total', 'paid_at'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'discount_type' => DiscountType::class,
            'number' => 'integer',
            'table_number' => 'integer',
            'covers' => 'integer',
            'subtotal' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * Place a paid order for the given operational day. The service type is
     * derived from the table number: set means table service, null means pickup.
     *
     * @param  array<int, array{food: Food, quantity: int, ingredients?: array<int, array{ingredient: Ingredient, quantity: int}>}>  $items
     *
     * @throws OrderException
     */
    public static function place(
        EventDay $day,
        ?CashRegister $register,
        ?User $operator,
        ?int $tableNumber,
        ?string $customerName,
        PaymentMethod $paymentMethod,
        array $items,
        ?DiscountType $discountType = null,
        ?int $discountValue = null,
        ?int $covers = null,
    ): self {
        if ($items === []) {
            throw new OrderException('Un ordine deve contenere almeno una pietanza.');
        }

        if ($tableNumber !== null && $tableNumber < 1) {
            throw new OrderException('Numero tavolo non valido.');
        }

        if ($discountType === DiscountType::Percentage && ($discountValue < 0 || $discountValue > 100)) {
            throw new OrderException('La percentuale di sconto deve essere tra 0 e 100.');
        }

        // Retry on a lost number race: (event_day_id, number) is unique.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(fn (): self => static::build(
                    $day, $register, $operator, $tableNumber, $customerName, $covers, $paymentMethod, $items, $discountType, $discountValue,
                ));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new OrderException('Impossibile assegnare un numero ordine, riprova.');
    }

    /**
     * The next progressive order number for the given day (starts at 1).
     */
    public static function nextNumberForDay(EventDay $day): int
    {
        return (static::query()->where('event_day_id', $day->id)->max('number') ?? 0) + 1;
    }

    /**
     * @param  array<int, array{food: Food, quantity: int, ingredients?: array<int, array{ingredient: Ingredient, quantity: int}>}>  $items
     */
    protected static function build(
        EventDay $day,
        ?CashRegister $register,
        ?User $operator,
        ?int $tableNumber,
        ?string $customerName,
        ?int $covers,
        PaymentMethod $paymentMethod,
        array $items,
        ?DiscountType $discountType,
        ?int $discountValue,
    ): self {
        $order = static::create([
            'event_day_id' => $day->id,
            'cash_register_id' => $register?->id,
            'user_id' => $operator?->id,
            'number' => static::nextNumberForDay($day),
            'table_number' => $tableNumber,
            'customer_name' => $customerName,
            'covers' => $covers,
            'service_type' => $tableNumber !== null ? ServiceType::TableService : ServiceType::Pickup,
            'status' => OrderStatus::Paid,
            'payment_method' => $paymentMethod,
            'subtotal' => 0,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => 0,
            'total' => 0,
            'paid_at' => now(),
        ]);

        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += $order->addLine($item['food'], $item['quantity'], $item['ingredients'] ?? [], $item['note'] ?? null)->line_total;
        }

        $discountAmount = static::calculateDiscount($subtotal, $discountType, $discountValue);

        $order->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total' => $subtotal - $discountAmount,
        ])->save();

        return $order;
    }

    /**
     * Resolve the discount amount (in cents), never exceeding the subtotal.
     */
    public static function calculateDiscount(int $subtotal, ?DiscountType $type, ?int $value): int
    {
        $amount = match ($type) {
            DiscountType::Fixed => $value ?? 0,
            DiscountType::Percentage => intdiv($subtotal * ($value ?? 0), 100),
            null => 0,
        };

        return min($amount, $subtotal);
    }

    /**
     * @param  array<int, array{ingredient: Ingredient, quantity: int}>  $chosenIngredients
     *
     * @throws OrderException
     */
    protected function addLine(Food $food, int $quantity, array $chosenIngredients, ?string $note = null): OrderLine
    {
        if ($quantity < 1) {
            throw new OrderException("Quantità non valida per \"{$food->name}\".");
        }

        $line = $this->lines()->create([
            'food_id' => $food->id,
            'food_name' => $food->name,
            'unit_price' => $food->price,
            'quantity' => $quantity,
            'line_total' => 0,
            'note' => $note,
        ]);

        $lineIngredients = collect($chosenIngredients)->map(function (array $chosen) use ($food, $line): OrderLineIngredient {
            $ingredient = $chosen['ingredient'];
            $pivot = $food->ingredients->firstWhere('id', $ingredient->id)?->pivot;

            if ($pivot === null) {
                throw new OrderException("\"{$ingredient->name}\" non fa parte di \"{$food->name}\".");
            }

            if ($chosen['quantity'] < $pivot->min_quantity || $chosen['quantity'] > $pivot->max_quantity) {
                throw new OrderException("Quantità di \"{$ingredient->name}\" fuori dai limiti consentiti.");
            }

            return $line->ingredients()->create([
                'ingredient_id' => $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'quantity' => $chosen['quantity'],
                'base_quantity' => $pivot->quantity,
                'surcharge' => $ingredient->surcharge,
            ]);
        });

        $line->setRelation('ingredients', $lineIngredients);
        $line->forceFill(['line_total' => $line->computeTotal()])->save();

        return $line;
    }
}
