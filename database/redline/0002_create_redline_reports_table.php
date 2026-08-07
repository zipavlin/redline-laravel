<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->create('redline_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->default('new'); // new | noticed | progress | resolved | done
            $table->uuid('author_id')->nullable();
            $table->string('url', 2048);
            $table->string('location', 2048);
            $table->text('body')->nullable();
            $table->json('pin')->nullable();
            $table->string('element', 1024)->nullable();
            $table->json('browser')->nullable();
            $table->timestamps();

            $table->index('location');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_reports');
    }
};
