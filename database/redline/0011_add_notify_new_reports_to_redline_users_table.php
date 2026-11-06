<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->table('redline_users', function (Blueprint $table): void {
            $table->boolean('notify_new_reports')->default(true); // per-user opt-out of the new-report email
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->table('redline_users', function (Blueprint $table): void {
            $table->dropColumn('notify_new_reports');
        });
    }
};
