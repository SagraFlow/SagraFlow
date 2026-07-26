<?php

namespace App\Models;

use App\Enums\PrinterStatus;
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

    protected $fillable = ['name', 'ip_address', 'port', 'active', 'status', 'status_detail', 'status_changed_at', 'last_checked_at', 'last_error'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'port' => 'integer',
            'status' => PrinterStatus::class,
            'status_changed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * Records the outcome of a status probe, stamping status_changed_at only
     * when the status actually changes (so grace timers are measured from the
     * moment the printer entered the state).
     */
    public function recordStatus(PrinterStatus $status, ?string $detail = null): void
    {
        $changed = $this->status !== $status;

        $this->forceFill([
            'status' => $status,
            'status_detail' => $detail,
            'last_checked_at' => now(),
            'status_changed_at' => $changed ? now() : $this->status_changed_at,
            'last_error' => $status === PrinterStatus::Ready ? null : ($detail ?? $this->last_error),
        ])->save();
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
