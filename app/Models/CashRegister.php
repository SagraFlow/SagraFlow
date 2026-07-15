<?php

namespace App\Models;

use App\Models\Concerns\Activatable;
use App\Models\Concerns\NormalizesName;
use Database\Factories\CashRegisterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegister extends Model
{
    use Activatable;

    /** @use HasFactory<CashRegisterFactory> */
    use HasFactory;
    use NormalizesName;

    protected $fillable = ['name', 'printer_id', 'active'];

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
}
