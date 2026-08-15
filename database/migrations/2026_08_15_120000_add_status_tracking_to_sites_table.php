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
        Schema::table('sites', function (Blueprint $table) {
            $table->string('status', 10)->default('unknown')->after('last_check_at');
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('status_changed_at');
            $table->unsignedSmallInteger('consecutive_successes')->default(0)->after('consecutive_failures');
            $table->unsignedTinyInteger('down_confirmations')->default(2)->after('consecutive_successes');
            $table->unsignedTinyInteger('up_confirmations')->default(2)->after('down_confirmations');
            $table->unsignedSmallInteger('alert_count')->default(0)->after('up_confirmations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'status_changed_at',
                'consecutive_failures',
                'consecutive_successes',
                'down_confirmations',
                'up_confirmations',
                'alert_count',
            ]);
        });
    }
};
