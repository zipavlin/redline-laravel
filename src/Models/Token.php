<?php

namespace Zipavlin\Redline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A long-lived MCP API token (`rlm_` + 40 hex chars), issued per user by the
 * `redline:mcp` artisan command. Only the unsalted sha-256 hash is stored;
 * the plaintext is returned once at creation and never serialized - hence the
 * `$hidden` guard on `token_hash`.
 */
class Token extends Model
{
    use HasUuids;

    protected $connection = 'redline';

    protected $table = 'redline_tokens';

    public $timestamps = false;

    protected $fillable = [
        'name',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'security_epoch' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The only creation path for an API token: generates the plaintext (shown
     * and stored exactly once), stores the hash via forceFill because a hash
     * must never be mass-assignable (it is deliberately not in $fillable),
     * records the last 4 characters of the plaintext as `token_hint` (also via
     * forceFill alongside the hash - it is an identification hint for the
     * roster, never a credential: 4 characters of a 160-bit token), stamps
     * `created_at` (timestamps stay off - the table has no updated_at), and
     * snapshots the owner's current password_epoch so a later password change
     * invalidates the token.
     */
    public static function issue(User $user, string $name): string
    {
        $plaintext = self::generatePlaintext();

        $user->tokens()->forceCreate([
            'token_hash' => self::hash($plaintext),
            'token_hint' => self::hint($plaintext),
            'name' => $name,
            'security_epoch' => (int) ($user->password_epoch ?? 0),
            'created_at' => now(),
        ]);

        return $plaintext;
    }

    /**
     * Identification hint for the roster: the last 4 characters of the
     * plaintext - 4 hex chars of a 160-bit random token. Never use it as a
     * credential.
     */
    public static function hint(string $plaintext): string
    {
        return substr($plaintext, -4);
    }

    /**
     * Unsalted sha-256 hex of a plaintext token - the lookup key on the read
     * path. Unsalted is deliberate: the token is high-entropy random bytes, so
     * a password-style slow hash buys nothing.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Generate a new plaintext token: `rlm_` + 40 hex chars (random_bytes(20)).
     */
    public static function generatePlaintext(): string
    {
        return 'rlm_'.bin2hex(random_bytes(20));
    }

    /**
     * Whether the token is revoked (soft revocation sets `revoked_at`).
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
