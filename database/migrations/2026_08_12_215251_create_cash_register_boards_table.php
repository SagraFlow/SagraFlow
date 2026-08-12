<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tab bar as one station sees it: which boards, in which order. The
        // station opens on the first one it shows, so there is no separate
        // "default board" to keep in step with this list.
        //
        // No rows for a register means the default bar (the complete tab first,
        // then every board in the order the organiser gave them): a fresh
        // install works unconfigured, and a board added later shows up on every
        // station that was not deliberately arranged.
        Schema::create('cash_register_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            // Null is the generated "Tutti" tab, which has no row of its own but
            // takes a place in the bar like any other.
            $table->foreignId('menu_tab_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('visible')->default(true);

            $table->unique(['cash_register_id', 'menu_tab_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_boards');
    }
};
