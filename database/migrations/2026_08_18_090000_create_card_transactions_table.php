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
        Schema::create('card_transactions', function (Blueprint $table) {
            $table->id();

            // The attempt exists before the order does, and outlives the till
            // session: a payment can end while the tablet is gone, and someone
            // has to be able to read afterwards what the terminal said. The
            // order is filled in only once it has been placed.
            $table->foreignId('card_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots of who was asked and who asked, kept as sent, so the row
            // still reads correctly after a terminal is renamed or removed.
            $table->string('terminal_id', 8);
            $table->string('ecr_id', 8);

            $table->unsignedInteger('amount_cents');
            $table->string('status', 20);
            $table->string('outcome', 2)->nullable();

            // What the terminal reported. The amount from the host is the field
            // that lets a recovered result be recognised as this one.
            $table->unsignedInteger('amount_from_host_cents')->nullable();
            $table->string('authorization_code', 16)->nullable();
            $table->string('stan', 16)->nullable();
            $table->string('transaction_type', 8)->nullable();
            $table->string('card_type', 1)->nullable();
            $table->string('pan_last4', 4)->nullable();
            $table->string('host_datetime', 16)->nullable();
            $table->string('acquirer_id', 16)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('currency_exchanged')->default(false);

            // Confirmed by a person rather than by the terminal: the way out
            // when the integration cannot answer and the sale must go on.
            $table->boolean('manual')->default(false);

            // The last line the terminal showed the customer, so the cashier
            // watching the till knows what is being asked of them.
            $table->string('progress', 40)->nullable();
            $table->string('error', 255)->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            // Recovery asks whether a STAN has been seen before on a terminal.
            $table->index(['card_terminal_id', 'stan']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_transactions');
    }
};
