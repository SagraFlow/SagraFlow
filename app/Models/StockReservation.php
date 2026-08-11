<?php

namespace App\Models;

use App\Exceptions\StockUnavailableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A short-lived hold on ingredient stock taken when a checkout starts its
 * payment, so a customer never pays for goods another register consumes in the
 * meantime. The held units are decremented from the ingredients up front and
 * given back when the hold is released (cancel or expiry) or consumed for good
 * when the order is placed.
 */
class StockReservation extends Model
{
    protected $fillable = ['held', 'expires_at'];

    protected function casts(): array
    {
        return [
            'held' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Atomically holds the given ingredient units for `$ttlSeconds`, decrementing
     * their stock. Returns the reservation, or null (changing nothing) when any
     * tracked ingredient is short. Untracked ingredients (null stock) are
     * unlimited and never held.
     *
     * @param  array<int, int>  $needByIngredient  ingredient id => units required
     */
    public static function reserve(array $needByIngredient, int $ttlSeconds): ?self
    {
        ksort($needByIngredient); // deterministic id order avoids deadlocks

        try {
            return DB::transaction(function () use ($needByIngredient, $ttlSeconds): self {
                $ingredients = Ingredient::whereIn('id', array_keys($needByIngredient))->get()->keyBy('id');
                $held = [];

                foreach ($needByIngredient as $ingredientId => $units) {
                    $ingredient = $ingredients->get($ingredientId);

                    if ($ingredient === null || $ingredient->stock === null) {
                        continue; // vanished or untracked: nothing to hold
                    }

                    if (! $ingredient->consume($units)) {
                        throw new StockUnavailableException;
                    }

                    $held[$ingredientId] = $units;
                }

                return static::create([
                    'held' => $held,
                    'expires_at' => now()->addSeconds($ttlSeconds),
                ]);
            });
        } catch (StockUnavailableException) {
            return null;
        }
    }

    /**
     * Whether any current reservation holds units of the given ingredient, i.e.
     * its stock is partly committed to a checkout in progress. Used to block an
     * admin from editing that ingredient's stock while it would desync a hold.
     */
    public static function holdsIngredient(int $ingredientId): bool
    {
        return static::all()->contains(
            fn (self $reservation): bool => array_key_exists($ingredientId, $reservation->held ?? []),
        );
    }

    /**
     * Pushes the expiry forward, keeping the hold alive while the checkout's
     * payment screen is still open (a heartbeat from the browser).
     */
    public function renew(int $ttlSeconds): void
    {
        $this->update(['expires_at' => now()->addSeconds($ttlSeconds)]);
    }

    /**
     * Gives the held units back to their ingredients and drops the reservation.
     * The row is claimed with a conditional delete first, so a hold the reaper
     * released in the meantime is not given back a second time.
     */
    public function release(): void
    {
        DB::transaction(function (): void {
            if (static::whereKey($this->getKey())->delete() === 0) {
                return; // already released elsewhere: its units are back
            }

            static::restoreHeld($this->held ?? []);
        });
    }

    /**
     * Claims this hold for an order about to be committed: the row is dropped
     * without giving the units back, so the stock stays decremented for the
     * order. Returns false when the hold was already gone (released by the
     * reaper), meaning the order has to decrement the stock itself.
     */
    public function claim(): bool
    {
        return static::whereKey($this->getKey())->delete() > 0;
    }

    /**
     * Gives held units back to their ingredients. Untracked ingredients and
     * ones that vanished meanwhile are skipped.
     *
     * @param  array<int, int>  $held  ingredient id => units
     */
    public static function restoreHeld(array $held): void
    {
        foreach ($held as $ingredientId => $units) {
            Ingredient::whereKey($ingredientId)->whereNotNull('stock')->increment('stock', $units);
        }
    }

    /**
     * Releases every reservation past its expiry, returning how many were freed.
     * Each row is claimed with a conditional delete (still expired), so a hold
     * the browser heartbeat renewed between the scan and the release is left
     * untouched instead of being wrongly given back.
     */
    public static function releaseExpired(): int
    {
        $released = 0;

        static::query()->where('expires_at', '<', now())->get()->each(function (self $reservation) use (&$released): void {
            $freed = DB::transaction(function () use ($reservation): bool {
                $claimed = static::whereKey($reservation->getKey())
                    ->where('expires_at', '<', now())
                    ->delete();

                if ($claimed === 0) {
                    return false; // renewed in the meantime: keep it, give nothing back
                }

                static::restoreHeld($reservation->held ?? []);

                return true;
            });

            if ($freed) {
                $released++;
            }
        });

        return $released;
    }
}
