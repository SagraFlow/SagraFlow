<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('event.eventName', '');
        $this->migrator->add('event.coverCharge', 0);
    }
};
