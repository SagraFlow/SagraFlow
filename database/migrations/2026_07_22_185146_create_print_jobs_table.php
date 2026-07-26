<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            // Nullable so system prints (e.g. a printer test) exist without an order.
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            // Printer name frozen at print time, so the log survives printer deletion.
            $table->string('printer_name')->nullable();
            $table->string('type');
            $table->string('label');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            // Compact document spec (items, event name, ...) so the worker can
            // render the ticket at send time from this row + the immutable order,
            // without storing raw bytes.
            $table->json('spec')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
