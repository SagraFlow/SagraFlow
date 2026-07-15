<?php

namespace App\Models;

use App\Exceptions\PrinterException;
use App\Models\Concerns\NormalizesName;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Printer extends Model
{
    /** @use HasFactory<PrinterFactory> */
    use HasFactory;

    use NormalizesName;

    protected $fillable = ['name', 'ip_address', 'port', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Printer $printer): void {
            if ($printer->cashRegister()->exists()) {
                throw new PrinterException('Impossibile eliminare una stampante collegata a una cassa.');
            }
        });
    }

    public function cashRegister(): HasOne
    {
        return $this->hasOne(CashRegister::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
