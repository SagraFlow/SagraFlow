<?php

namespace App\Models;

use App\Exceptions\PrinterException;
use App\Models\Concerns\Activatable;
use App\Models\Concerns\NormalizesName;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Printer extends Model
{
    use Activatable;

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
            if ($printer->cashRegister()->exists() || $printer->printRoutes()->exists()) {
                throw new PrinterException('Impossibile eliminare una stampante collegata a una cassa o a una configurazione di stampa.');
            }
        });
    }

    public function cashRegister(): HasOne
    {
        return $this->hasOne(CashRegister::class);
    }

    public function printRoutes(): HasMany
    {
        return $this->hasMany(PrintRoute::class);
    }

    /**
     * Printers not used as a cash register's local printer, and therefore
     * eligible as a department print destination.
     */
    public function scopeNotAssignedToCashRegister($query)
    {
        return $query->whereDoesntHave('cashRegister');
    }
}
