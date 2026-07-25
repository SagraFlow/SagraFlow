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
            // Null for a covers route (for_covers = true), which is a standalone
            // print subject not tied to any product category.
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('service_type');
            $table->string('destination');
            // Document produced by this route: 'department_ticket' (comanda) or 'pickup_stub' (tagliandino).
            $table->string('document')->default('department_ticket');
            $table->foreignId('printer_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('grouped')->default(true);
            // Route for the order's covers (coperti) rather than a category's lines.
            $table->boolean('for_covers')->default(false);
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
