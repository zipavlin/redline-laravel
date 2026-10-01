<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->create('redline_members', function (Blueprint $table): void {
            $table->uuid('report_id');
            $table->uuid('user_id');

            // Composite primary key doubles as the uniqueness guarantee for a
            // report-user pair, so attach is idempotent without a surrogate id.
            $table->primary(['report_id', 'user_id']);
            $table->index('user_id');

            $table->foreign('report_id')
                ->references('id')
                ->on('redline_reports')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('redline_users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_members');
    }
};
