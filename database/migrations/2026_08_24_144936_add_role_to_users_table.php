<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The cautious default: an account created without saying anything
            // reaches the till and not the panel.
            $table->string('role')->default(UserRole::Cashier->value)->after('email');
        });

        // Everyone who existed before roles did was, by definition, running the
        // place: leaving them as cashiers would lock the panel behind nobody.
        DB::table('users')->update(['role' => UserRole::Administrator->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
