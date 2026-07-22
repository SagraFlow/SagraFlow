<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Relative path (on the public disk) of the receipt logo, null when unset.
        $this->migrator->add('event.logo', null);
    }
};
