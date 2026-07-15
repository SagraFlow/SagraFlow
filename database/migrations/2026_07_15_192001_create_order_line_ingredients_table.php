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
        Schema::create('order_line_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ingredient_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('base_quantity');
            $table->unsignedInteger('surcharge');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_line_ingredients');
    }
};
