<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->table('redline_reports', function (Blueprint $table): void {
            $table->dateTime('due_at')->nullable(); // triage deadline, always UTC
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->table('redline_reports', function (Blueprint $table): void {
            $table->dropIndex(['due_at']);
            $table->dropColumn('due_at');
        });
    }
};
