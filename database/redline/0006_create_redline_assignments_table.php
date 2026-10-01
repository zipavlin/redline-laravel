<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->create('redline_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->unique(['report_id', 'user_id']);
            $table->index('user_id');

            // Deliberately no foreign keys here (unlike 0005): the File (Orbit)
            // driver cannot enforce them, so cleanup is per-model delete on both
            // drivers rather than a DB cascade that would only exist on one.
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_assignments');
    }
};
