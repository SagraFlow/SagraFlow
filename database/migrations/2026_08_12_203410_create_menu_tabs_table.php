<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_tabs', function (Blueprint $table) {
            $table->id();
            // Not unique: one sagra can have a "Bar" board for the bar station
            // and another "Bar" on the general till. The description is what
            // tells them apart, and it is only ever read by whoever arranges the
            // boards, never by the cashier, who reads the name in the bar.
            $table->string('name', 100);
            $table->string('description', 150)->nullable();
            // Board size. Fixed on purpose: a till board that reflows or scrolls
            // loses the stable positions the cashiers build their speed on.
            $table->unsignedTinyInteger('columns')->default(5);
            $table->unsignedTinyInteger('rows')->default(4);
            // No position column: the id already carries the order boards were
            // created in, which is all the default bar needs. Where the order
            // actually matters it is per station, in cash_register_boards.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_tabs');
    }
};
