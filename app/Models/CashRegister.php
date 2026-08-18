<?php

namespace App\Models;

use App\Models\Concerns\Activatable;
use App\Models\Concerns\NormalizesName;
use Database\Factories\CashRegisterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    use Activatable;

    /** @use HasFactory<CashRegisterFactory> */
    use HasFactory;

    use NormalizesName;

    protected $fillable = ['name', 'printer_id', 'card_terminal_id', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * The card terminal this station takes card payments on. Null while a
     * station has none: the card flow then stays the manual one.
     */
    public function cardTerminal(): BelongsTo
    {
        return $this->belongsTo(CardTerminal::class);
    }

    /**
     * This station's tab bar, in order. Empty means the default bar: see the
     * table's migration for why it is implicit rather than stored.
     */
    public function boards(): HasMany
    {
        return $this->hasMany(CashRegisterBoard::class)->orderBy('position');
    }
}
