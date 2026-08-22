<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alert grace & re-alert
    |--------------------------------------------------------------------------
    |
    | An offline printer alerts immediately. A reachable-but-not-ready printer
    | (paper out / cover open / error) alerts only after it has stayed that way
    | for `grace_seconds`. The alert is edge-triggered: it fires once per outage
    | and is re-armed only when the printer recovers, so a persistent fault never
    | re-notifies.
    |
    */

    'grace_seconds' => (int) env('PRINT_GRACE_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Status probe timeouts
    |--------------------------------------------------------------------------
    */

    'poll' => [
        'connect_timeout' => (int) env('PRINT_PROBE_CONNECT_TIMEOUT', 2),
        'read_timeout_ms' => (int) env('PRINT_PROBE_READ_TIMEOUT_MS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciler
    |--------------------------------------------------------------------------
    |
    | A job left in "Sending" longer than this (a dead worker mid-send) is
    | reclaimed and re-dispatched.
    |
    */

    'reconcile' => [
        // Thirty seconds is long enough that a send in flight is never reclaimed
        // (a whole order goes over the wire in about two) and short enough that a
        // worker killed mid-print costs half a minute rather than two.
        'stale_seconds' => (int) env('PRINT_RECONCILE_STALE_SECONDS', 30),
    ],

];
