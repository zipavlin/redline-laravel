<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        Schema::connection('redline')->create('redline_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role'); // reporter | developer | admin
            $table->string('password')->nullable();
            $table->string('status')->default('approved'); // approved | pending
            $table->unsignedInteger('token_version')->default(0); // bumped on logout / password change to revoke JWTs
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_users');
    }
};
