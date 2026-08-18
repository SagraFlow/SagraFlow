<?php

namespace App\Models;

use App\Exceptions\CardTerminalException;
use App\Models\Concerns\Activatable;
use App\Models\Concerns\NormalizesName;
use Database\Factories\CardTerminalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A card payment terminal on the network, spoken to with the Nexi ECR protocol:
 * the till sends the amount, the terminal takes the payment and answers.
 */
class CardTerminal extends Model
{
    use Activatable;

    /** @use HasFactory<CardTerminalFactory> */
    use HasFactory;

    use NormalizesName;

    protected $fillable = ['name', 'ip_address', 'port', 'terminal_id', 'active'];

    /**
     * How long a claim survives without being renewed. Long enough for a real
     * transaction (a card looked for in a wallet, a PIN typed twice), short
     * enough that a till which never comes back does not hold the terminal for
     * the rest of the evening.
     */
    public const CLAIM_TTL_SECONDS = 300;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'port' => 'integer',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (CardTerminal $terminal): void {
            if ($terminal->cashRegisters()->exists()) {
                throw new CardTerminalException('Impossibile eliminare un terminale collegato a una cassa.');
            }
        });
    }

    /**
     * The stations that take cards on this terminal. More than one is allowed:
     * a terminal can be shared, it just cannot run two payments at once, and
     * that is settled at payment time by waiting for it to be free.
     */
    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    /** The station holding the terminal right now, expired claims aside. */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'claimed_by_cash_register_id');
    }

    /**
     * Takes the terminal for the given station, if it is free or already theirs
     * (claiming again renews, so a payment in progress keeps its hold). Returns
     * false, changing nothing, when another station is on it: the caller then
     * tells the cashier the terminal is busy instead of queueing behind a
     * transaction of unknown length.
     *
     * The claim is won with one conditional update, so two tills asking at the
     * same instant cannot both walk away believing they have it.
     */
    public function claim(CashRegister $register): bool
    {
        $won = static::whereKey($this->getKey())
            ->where(function ($query) use ($register): void {
                $query->whereNull('claim_expires_at')
                    ->orWhere('claim_expires_at', '<', now())
                    ->orWhere('claimed_by_cash_register_id', $register->getKey());
            })
            ->update([
                'claimed_by_cash_register_id' => $register->getKey(),
                'claimed_at' => now(),
                'claim_expires_at' => now()->addSeconds(static::CLAIM_TTL_SECONDS),
            ]);

        if ($won === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /**
     * Gives the terminal back. Only the station holding it can, so a release
     * arriving late (from a payment whose claim already expired and was taken
     * by someone else) cannot free a transaction in progress.
     */
    public function release(CashRegister $register): bool
    {
        $released = static::whereKey($this->getKey())
            ->where('claimed_by_cash_register_id', $register->getKey())
            ->update([
                'claimed_by_cash_register_id' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);

        $this->refresh();

        return $released > 0;
    }

    /** Whether a live claim is on the terminal (an expired one is not one). */
    public function isBusy(): bool
    {
        return $this->claim_expires_at !== null && $this->claim_expires_at->isFuture();
    }

    /**
     * Whether this station can start a payment on the terminal: free, or held
     * by the station itself.
     */
    public function isAvailableFor(CashRegister $register): bool
    {
        return ! $this->isBusy() || $this->claimed_by_cash_register_id === $register->getKey();
    }

    /** The station on the terminal, for telling the cashier who has it. */
    public function busyRegisterName(): ?string
    {
        return $this->isBusy() ? $this->claimedBy?->name : null;
    }
}
