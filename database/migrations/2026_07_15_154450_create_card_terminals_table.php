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
        Schema::create('card_terminals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // We are the ECR and always open the connection, so a terminal is
            // reached by address: it needs a reserved one on the network.
            $table->string('ip_address', 45);
            $table->unsignedInteger('port');
            // The 8-digit id Nexi assigns to the terminal, echoed in every
            // message of the protocol. Kept as a string: it is an identifier
            // with meaningful leading zeros, never a number to do sums with.
            $table->string('terminal_id', 8)->unique();
            $table->boolean('active')->default(true);
            // Who is on the terminal right now. A shared terminal takes one
            // payment at a time, so a station claims it before starting and
            // gives it back after: whoever finds it taken is told so and
            // decides (wait, or take cash), rather than being queued behind a
            // transaction whose length nobody can predict.
            //
            // The claim carries an expiry because the holder can die mid-way (a
            // tablet that goes flat, a browser that never comes back): without
            // it the terminal would stay locked for the rest of the evening.
            // No foreign key here - cash_registers is created after this table,
            // so the constraint is added there.
            $table->unsignedBigInteger('claimed_by_cash_register_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['ip_address', 'port']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_terminals');
    }
};
