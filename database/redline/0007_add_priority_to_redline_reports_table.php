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
            $table->string('priority')->default('normal'); // low | normal | high | critical
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->table('redline_reports', function (Blueprint $table): void {
            $table->dropIndex(['priority']);
            $table->dropColumn('priority');
        });
    }
};
