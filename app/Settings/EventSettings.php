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

    public static function group(): string
    {
        return 'event';
    }
}
