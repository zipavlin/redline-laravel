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
            // Per-user counter advancing ONLY on a real password change
            // (User::updating hook). API tokens snapshot it at creation; a later
            // password change makes every older token 401. Logout and
            // bumpTokenVersion() never touch it.
            $table->integer('password_epoch')->default(0);
        });

        Schema::connection('redline')->create('redline_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id');

            // Unsalted sha-256 hex of the plaintext token (the plaintext is
            // high-entropy and shown once, so a plain hash lookup is correct).
            // Never serialized - the model hides it.
            $table->string('token_hash')->unique();
            $table->string('name')->default('');

            // Last 4 characters of the plaintext, for identification in the
            // `--list` roster only. Four characters of a 160-bit token reveal
            // nothing usable - this is an identification hint, NEVER a
            // credential, and it is not mass-assignable (Token::issue writes
            // it via forceFill alongside the hash).
            $table->string('token_hint', 4)->default('');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Snapshot of the owner's password_epoch at creation; the read path
            // compares only these two values.
            $table->integer('security_epoch');

            $table->timestamp('created_at')->nullable();
            $table->index('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('redline_users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('redline')->dropIfExists('redline_tokens');

        Schema::connection('redline')->table('redline_users', function (Blueprint $table): void {
            $table->dropColumn('password_epoch');
        });
    }
};
