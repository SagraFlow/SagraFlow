<?php

namespace App\Printing;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Exclusive access to one printer. Every transmission and every health probe
 * takes this lock, because an Epson network interface accepts a single
 * connection at a time: without it a health poll steals the socket from a send
 * in flight and reads a status that says nothing about the print.
 */
class PrinterLock
{
    /**
     * Runs the callback while holding the printer's lock, and reports whether it
     * ran: false means another process holds the printer right now. The lock
     * expires after $ttl seconds, so a process killed while holding a printer
     * blocks it for that long at most: pass the time the work can possibly take.
     */
    public function run(int $printerId, Closure $callback, int $ttl = 90): bool
    {
        $lock = Cache::lock('printer:'.$printerId, $ttl);

        if (! $lock->get()) {
            return false;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }

        return true;
    }
}
