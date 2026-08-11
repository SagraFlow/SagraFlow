<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stock reservation TTL
    |--------------------------------------------------------------------------
    |
    | Seconds a checkout holds ingredient stock once the payment starts. The
    | hold is released on cancel, consumed when the order is placed, or freed
    | by `stock:release-reservations` once it expires (a card payment left
    | hanging, the browser closed mid-sale, ...).
    |
    */

    'reservation_ttl' => (int) env('STOCK_RESERVATION_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Maximum hold
    |--------------------------------------------------------------------------
    |
    | Absolute lifetime of a hold from when the payment started, regardless of
    | the browser heartbeat. A payment screen left open past this is treated as
    | abandoned: the hold is released and the cashier is sent back to the cart
    | with a notice. Guards against a forgotten open payment tying up stock.
    |
    */

    'max_hold' => (int) env('STOCK_MAX_HOLD', 900),

];
