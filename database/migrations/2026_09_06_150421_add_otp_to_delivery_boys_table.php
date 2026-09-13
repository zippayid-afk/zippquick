<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_boys', function (Blueprint $table) {
            $table->string('otp', 4)->nullable()->after('mobile');
            $table->timestamp('otp_expires_at')->nullable()->after('otp');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_boys', function (Blueprint $table) {
            $table->dropColumn(['otp', 'otp_expires_at']);
        });
    }
};
