<?php

namespace App\Console\Commands;

use App\Models\StockReservation;
use Illuminate\Console\Command;

/**
 * Frees stock held by checkouts that started a payment but never confirmed nor
 * cancelled it (a hanging card payment, a browser closed mid-sale), so held
 * ingredients return to availability once the hold expires.
 */
class ReleaseExpiredReservations extends Command
{
    protected $signature = 'stock:release-reservations';

    protected $description = 'Release expired stock reservations back to ingredient stock.';

    public function handle(): int
    {
        $released = StockReservation::releaseExpired();

        $this->info("Rilasciate {$released} riserve scadute.");

        return self::SUCCESS;
    }
}
