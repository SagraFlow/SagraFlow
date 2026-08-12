<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a station's tab bar: a board (or the generated "Tutti" tab, when
 * the board is null), where it sits and whether it shows there.
 */
class CashRegisterBoard extends Model
{
    public $timestamps = false;

    protected $fillable = ['cash_register_id', 'menu_tab_id', 'position', 'visible'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'visible' => 'boolean',
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function menuTab(): BelongsTo
    {
        return $this->belongsTo(MenuTab::class);
    }
}
