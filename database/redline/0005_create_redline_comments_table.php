<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->create('redline_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_id');
            $table->uuid('author_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('report_id')
                ->references('id')
                ->on('redline_reports')
                ->onDelete('cascade');

            $table->foreign('author_id')
                ->references('id')
                ->on('redline_users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_comments');
    }
};
