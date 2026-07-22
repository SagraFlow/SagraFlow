<?php

namespace App\Models;

use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Enums\ServiceType;
use Database\Factories\PrintRouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintRoute extends Model
{
    /** @use HasFactory<PrintRouteFactory> */
    use HasFactory;

    protected $fillable = ['category_id', 'service_type', 'destination', 'document', 'printer_id', 'grouped', 'position'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'destination' => PrintDestination::class,
            'document' => PrintJobType::class,
            'grouped' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PrintRoute $route): void {
            // A cash-register destination is resolved at runtime from the ordering
            // register, so it never carries a fixed printer.
            if ($route->destination === PrintDestination::CashRegister) {
                $route->printer_id = null;
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
