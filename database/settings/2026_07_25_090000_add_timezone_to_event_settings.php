<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // IANA timezone used to display and print times (stored in UTC).
        $this->migrator->add('event.timezone', 'Europe/Rome');
    }
};
