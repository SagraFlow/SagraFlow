<?php

namespace App\Models;

use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    protected $fillable = ['order_id', 'printer_id', 'printer_name', 'type', 'label', 'status', 'attempts', 'error', 'printed_at'];

    protected function casts(): array
    {
        return [
            'type' => PrintJobType::class,
            'status' => PrintJobStatus::class,
            'attempts' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
