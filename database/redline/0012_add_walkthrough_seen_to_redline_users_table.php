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
            $table->boolean('walkthrough_seen')->default(false); // first-run widget walkthrough, shown once per user
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->table('redline_users', function (Blueprint $table): void {
            $table->dropColumn('walkthrough_seen');
        });
    }
};
