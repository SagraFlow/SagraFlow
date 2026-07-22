<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class EventSettings extends Settings
{
    public string $eventName;

    /**
     * Cover charge (coperto) stored as an integer amount of cents.
     */
    public int $coverCharge;

    /**
     * Whether an order discount also reduces the cover charge (coperto).
     */
    public bool $discountAppliesToCover;

    /**
     * Relative path (on the public disk) of the logo printed on receipts.
     */
    public ?string $logo;

    public static function group(): string
    {
        return 'event';
    }
}
