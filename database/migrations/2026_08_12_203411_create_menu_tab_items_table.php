<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_tab_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_tab_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            // Absolute cell index on the board, 0 to (columns * rows - 1). A cell
            // with no row here is empty, which is the default state: emptiness is
            // never something the organiser has to place.
            $table->unsignedSmallInteger('slot');
            $table->timestamps();

            $table->unique(['menu_tab_id', 'slot']);
            // The same key twice on one board is never intentional.
            $table->unique(['menu_tab_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_tab_items');
    }
};
