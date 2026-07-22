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

    protected $fillable = ['event_day_id', 'cash_register_id', 'user_id', 'number', 'table_number', 'customer_name', 'covers', 'cover_charge', 'service_type', 'status', 'payment_method', 'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount_applies_to_cover', 'total', 'paid_at'];

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
            'cover_charge' => 'integer',
            'subtotal' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'discount_applies_to_cover' => 'boolean',
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
     * Total cover charge (coperto) for the order: covers × per-cover charge (in cents).
     */
    public function coverTotal(): int
    {
        return ($this->covers ?? 0) * $this->cover_charge;
    }

    /**
     * Place a paid order for the given operational day. The service type is
     * derived from the table number: set means table service, null means pickup.
     *
     * Line prices, names, surcharges and doses are frozen snapshots taken when
     * the items were added to the cart: they are persisted verbatim, never
     * re-read from the current Food/Ingredient records. The `*_id` values are
     * only used to re-link the (nullable) foreign keys and are stored as null
     * when the referenced record no longer exists.
     *
     * @param  array<int, array{food_id: ?int, food_name: string, unit_price: int, quantity: int, note?: ?string, ingredients?: array<int, array{ingredient_id: ?int, ingredient_name: string, quantity: int, base_quantity: int, surcharge: int}>}>  $items
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
        int $coverCharge = 0,
        bool $discountAppliesToCover = false,
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

        if ($discountType === DiscountType::Fixed && ($discountValue ?? 0) < 0) {
            throw new OrderException('Lo sconto non può essere negativo.');
        }

        if ($customerName !== null && mb_strlen($customerName) > 255) {
            throw new OrderException('Il nome cliente è troppo lungo.');
        }

        // Retry on a lost number race: (event_day_id, number) is unique.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(fn (): self => static::build(
                    $day, $register, $operator, $tableNumber, $customerName, $covers, $coverCharge, $paymentMethod, $items, $discountType, $discountValue, $discountAppliesToCover,
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
     * @param  array<int, array{food_id: ?int, food_name: string, unit_price: int, quantity: int, note?: ?string, ingredients?: array<int, array{ingredient_id: ?int, ingredient_name: string, quantity: int, base_quantity: int, surcharge: int}>}>  $items
     */
    protected static function build(
        EventDay $day,
        ?CashRegister $register,
        ?User $operator,
        ?int $tableNumber,
        ?string $customerName,
        ?int $covers,
        int $coverCharge,
        PaymentMethod $paymentMethod,
        array $items,
        ?DiscountType $discountType,
        ?int $discountValue,
        bool $discountAppliesToCover,
    ): self {
        $order = static::create([
            'event_day_id' => $day->id,
            'cash_register_id' => $register?->id,
            'user_id' => $operator?->id,
            'number' => static::nextNumberForDay($day),
            'table_number' => $tableNumber,
            'customer_name' => $customerName,
            'covers' => $covers,
            'cover_charge' => $coverCharge,
            'service_type' => $tableNumber !== null ? ServiceType::TableService : ServiceType::Pickup,
            'status' => OrderStatus::Paid,
            'payment_method' => $paymentMethod,
            'subtotal' => 0,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => 0,
            'discount_applies_to_cover' => $discountAppliesToCover,
            'total' => 0,
            'paid_at' => now(),
        ]);

        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += $order->addLine($item)->line_total;
        }

        $coverTotal = $order->coverTotal();
        $discountBase = $subtotal + ($order->discount_applies_to_cover ? $coverTotal : 0);
        $discountAmount = static::calculateDiscount($discountBase, $discountType, $discountValue);

        $order->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total' => $subtotal + $coverTotal - $discountAmount,
        ])->save();

        return $order;
    }

    /**
     * Resolve the discount amount (in cents), clamped between 0 and the subtotal.
     */
    public static function calculateDiscount(int $subtotal, ?DiscountType $type, ?int $value): int
    {
        $amount = match ($type) {
            DiscountType::Fixed => $value ?? 0,
            DiscountType::Percentage => intdiv($subtotal * ($value ?? 0), 100),
            null => 0,
        };

        return max(0, min($amount, $subtotal));
    }

    /**
     * Persist a single order line from its frozen cart snapshot. Prices, names,
     * surcharges and doses are stored verbatim; the `*_id` values re-link the
     * foreign keys and fall back to null when the record no longer exists.
     *
     * @param  array{food_id: ?int, food_name: string, unit_price: int, quantity: int, note?: ?string, ingredients?: array<int, array{ingredient_id: ?int, ingredient_name: string, quantity: int, base_quantity: int, surcharge: int}>}  $item
     *
     * @throws OrderException
     */
    protected function addLine(array $item): OrderLine
    {
        if ($item['quantity'] < 1) {
            throw new OrderException("Quantità non valida per \"{$item['food_name']}\".");
        }

        if (($item['note'] ?? null) !== null && mb_strlen($item['note']) > 255) {
            throw new OrderException("La nota per \"{$item['food_name']}\" è troppo lunga.");
        }

        $line = $this->lines()->create([
            'food_id' => $item['food_id'],
            'food_name' => $item['food_name'],
            'unit_price' => $item['unit_price'],
            'quantity' => $item['quantity'],
            'line_total' => 0,
            'note' => $item['note'] ?? null,
        ]);

        $lineIngredients = collect($item['ingredients'] ?? [])->map(fn (array $chosen): OrderLineIngredient => $line->ingredients()->create([
            'ingredient_id' => $chosen['ingredient_id'],
            'ingredient_name' => $chosen['ingredient_name'],
            'quantity' => $chosen['quantity'],
            'base_quantity' => $chosen['base_quantity'],
            'surcharge' => $chosen['surcharge'],
        ]));

        $line->setRelation('ingredients', $lineIngredients);
        $line->forceFill(['line_total' => $line->computeTotal()])->save();

        return $line;
    }
}
