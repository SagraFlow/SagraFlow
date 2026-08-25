<?php

namespace App\Printing\Concerns;

/**
 * Runs a socket call without PHP narrating its failure.
 *
 * Talking to a printer over a cable in a hall fails routinely: it is off, it is
 * unplugged, somebody moved the table. Every call here returns that failure and
 * every caller handles it, so PHP's warning carries nothing - and prefixing the
 * call with @ is not enough: a test runner reports suppressed diagnostics all
 * the same, which turns a green suite into a yellow one over failures the tests
 * asked for on purpose.
 *
 * Deliberately narrow: one call at a time, warnings and notices only, and the
 * handler is put back immediately afterwards - a test holds that last part.
 *
 * Keep the callable a single socket call and nothing else. While the handler is
 * installed, levels outside the mask skip the application's own handler, which
 * is harmless when the only thing running is fsockopen, fwrite or fread, and
 * would stop being harmless with application code in there.
 */
trait QuietSockets
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $call
     * @return TReturn
     */
    private function quietly(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
