<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('printer_id')->nullable()->unique()->constrained()->restrictOnDelete();
            // One terminal per register, but the same terminal can serve
            // several: a station has one place to take a card, while a terminal
            // can be shared. Not unique, unlike the printer above - what a
            // shared terminal cannot do is two payments at once, and that is
            // handled at payment time by waiting for it to be free.
            $table->foreignId('card_terminal_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // The other half of the pair: which station currently holds a terminal.
        // It lives on card_terminals (created first), so its constraint can
        // only be declared here. Nulled on delete - a station that no longer
        // exists cannot be holding anything.
        Schema::table('card_terminals', function (Blueprint $table) {
            $table->foreign('claimed_by_cash_register_id')->references('id')->on('cash_registers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
