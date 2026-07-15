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
        Schema::create('print_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('service_type');
            $table->string('destination');
            $table->foreignId('printer_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('grouped')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'service_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_routes');
    }
};
