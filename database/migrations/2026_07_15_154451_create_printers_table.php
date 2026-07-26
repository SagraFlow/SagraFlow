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
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('ip_address', 45);
            $table->unsignedInteger('port');
            $table->boolean('active')->default(true);
            // Health, updated by the status probe / health poll.
            $table->string('status')->default('unknown');
            $table->string('status_detail')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['ip_address', 'port']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
