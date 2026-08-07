<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->create('redline_screenshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_id');
            $table->string('path');

            $table->foreign('report_id')
                ->references('id')
                ->on('redline_reports')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_screenshots');
    }
};
