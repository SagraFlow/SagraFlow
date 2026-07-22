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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_day_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('table_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->unsignedInteger('covers')->nullable();
            // Per-cover charge (coperto) frozen at order time, in cents.
            $table->unsignedInteger('cover_charge')->default(0);
            $table->string('service_type');
            $table->string('status')->default('open');
            $table->string('payment_method')->nullable();
            $table->unsignedInteger('subtotal')->default(0);
            $table->string('discount_type')->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->unsignedInteger('discount_amount')->default(0);
            // Whether the discount was applied to the cover charge too, frozen at order time.
            $table->boolean('discount_applies_to_cover')->default(false);
            $table->unsignedInteger('total')->default(0);
            // Cash tendered for a cash payment (cents); null for card payments.
            $table->unsignedInteger('cash_received')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Progressive order number, unique within an operational day.
            $table->unique(['event_day_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
