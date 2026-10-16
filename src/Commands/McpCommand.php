<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Zipavlin\Redline\Commands\Concerns\AgentInstructions;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Token;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

/**
 * Create / list / revoke the long-lived MCP API tokens stored in
 * `redline_tokens`. Tokens carry the full permissions of the user they belong
 * to (no scopes), are issued only here on the server, and never expire on a
 * logout - see the lifecycle rules in the tool output below.
 *
 * Bare `php artisan redline:mcp` runs the interactive wizard (the default):
 * pick an approved non-guest user, then add a token - printing the chosen
 * agent's copy-paste setup block - or revoke one from the roster. The
 * explicit flags keep their classic non-interactive behaviour.
 */
class McpCommand extends Command
{
    use AgentInstructions;

    public $signature = 'redline:mcp
        {--name= : Label for a created token (or an agent slug to print its setup block)}
        {--revoke= : Token id to soft-revoke}
        {--revoke-all : Revoke every token of the user given via --user=}
        {--user= : Account email for create/--revoke-all/--list selection}
        {--list : List a user\'s tokens (name, hint, created, last used)}';

    public $description = 'Set up, create, list and revoke Redline MCP API tokens';

    /**
     * Must read: verbatim from the MCP contract - do not reword.
     */
    private const TOKEN_WARNING =
        "This token acts with the user's full permissions - treat it as a password; ".
        'it is NOT invalidated by logout; it IS invalidated by that user\'s password change.';

    /**
     * Above roughly 25 candidates a choice list stops being usable, so the
     * wizard switches to a typed filter/exact-email fallback instead.
     */
    private const USER_CHOICE_LIMIT = 25;

    public function handle(): int
    {
        if ($this->option('revoke') !== null) {
            return $this->revokeOne((string) $this->option('revoke'));
        }

        if ($this->option('revoke-all')) {
            return $this->revokeAll();
        }

        if ($this->option('list')) {
            return $this->listTokens();
        }

        if (! $this->bootStorage()) {
            return self::FAILURE;
        }

        if ($this->option('user') !== null || $this->option('name') !== null) {
            return $this->createToken();
        }

        return $this->wizard();
    }

    // Wizard

    /**
     * The default interactive path: pick an approved non-guest user, then add
     * a token (with the chosen agent's setup block) or revoke one from the
     * roster.
     */
    private function wizard(): int
    {
        $user = $this->selectWizardUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $roster = $user->tokens()->whereNull('revoked_at')->orderBy('created_at')->get();

        if ($roster->isNotEmpty()) {
            $this->renderRoster($roster);

            $action = $this->choice(
                "The user has {$roster->count()} active token(s). What next?",
                ['Add new token', 'Revoke a token'],
                0
            );

            if ($action === 'Revoke a token') {
                return $this->wizardRevoke($roster);
            }
        }

        return $this->wizardCreate($user);
    }

    /**
     * Picks a token owner: approved non-guest users only, via a choice list -
     * and a typed filter/exact-email fallback once the pool outgrows a usable
     * choice list. Returns null when a lookup fails or nothing candidates.
     */
    private function selectWizardUser(): ?User
    {
        $pool = $this->approvedNonGuestUsers();

        if ($pool->isEmpty()) {
            $this->error('No approved non-guest users exist. Create one with php artisan redline:user first.');

            return null;
        }

        while ($pool->count() > self::USER_CHOICE_LIMIT) {
            $narrowed = $this->narrowCandidatePool($pool);

            if ($narrowed === null) {
                return null;
            }

            if ($narrowed instanceof User) {
                return $narrowed;
            }

            $pool = $narrowed;
        }

        return $this->pickUserCandidate($pool);
    }

    /**
     * Only approved accounts that are not guests may hold tokens - guests and
     * pending users are never offered in the wizard's list.
     */
    private function approvedNonGuestUsers(): Collection
    {
        return app(User::class)
            ->where('status', 'approved')
            ->where('role', '!=', Role::GUEST->value)
            ->orderBy('name')
            ->orderBy('email')
            ->get();
    }

    /**
     * One narrowing round over a too-large pool. A typed exact email jumps
     * straight to the account (with the verbatim guest/pending refusals);
     * anything else is a name/email fragment that filters the pool. Returns
     * User for an exact match, the narrowed Collection otherwise, null on
     * failure.
     */
    private function narrowCandidatePool(Collection $pool): User|Collection|null
    {
        $term = trim((string) $this->ask(
            $pool->count().' approved users. Type a name or email fragment to filter (or an exact email):'
        ));

        if ($term === '') {
            $this->error('A search term is required.');

            return null;
        }

        if (filter_var($term, FILTER_VALIDATE_EMAIL)) {
            return $this->resolveExactCandidate($term);
        }

        $matches = $pool->filter(fn (User $user) => $this->matchesCandidateTerm($user, $term));

        if ($matches->isEmpty()) {
            $this->error("No candidate matches [{$term}].");

            return null;
        }

        return $matches;
    }

    /**
     * The exact-email branch of the fallback: verbatim guest/pending refusals
     * apply at this user-typed boundary. Returns the user or null.
     */
    private function resolveExactCandidate(string $term): ?User
    {
        $user = $this->lookupUser($term);

        if ($user !== null && $this->refuseGuestOrPending($user)) {
            return null;
        }

        return $user;
    }

    /**
     * Case-insensitive substring match of the term against name and email.
     */
    private function matchesCandidateTerm(User $user, string $term): bool
    {
        $needle = mb_strtolower($term);

        return str_contains(mb_strtolower((string) $user->email), $needle)
            || str_contains(mb_strtolower((string) $user->name), $needle);
    }

    private function pickUserCandidate(Collection $pool): User
    {
        $users = $pool->values();
        $labels = $users->map(fn (User $user) => "{$user->email} ({$user->role->value})")->all();

        $answer = $this->choice('Which account gets the token?', $labels, 0);

        return $users->get(array_search($answer, $labels, true));
    }

    /**
     * Adds a token in the wizard: pick the agent preset, name the token (the
     * preset's slug by default, a typed name for Other), settle a same-name
     * collision, then mint.
     */
    private function wizardCreate(User $user): int
    {
        $preset = $this->selectAgentPreset();

        $name = $preset !== null ? $preset['slug'] : trim((string) $this->ask('Name for the token (e.g. what will use it):'));

        if ($name === '') {
            $this->error('A token name is required.');

            return self::FAILURE;
        }

        $this->resolveNameCollision($user, $name);

        return $this->mintToken($user, $name, $preset ?? self::genericPreset());
    }

    private function selectAgentPreset(): ?array
    {
        $choices = collect(self::agentPresets())
            ->map(fn (array $preset) => $preset['label'])
            ->prepend('Other (type a custom name)')
            ->values()
            ->all();

        $answer = $this->choice('Which agent will use this token?', $choices, 0);

        return $answer === 'Other (type a custom name)' ? null : self::presetForLabel($answer);
    }

    /**
     * A token name that already has an active token for this user asks whether
     * to replace (soft-revoke the namesake, then mint) or add an extra one.
     */
    private function resolveNameCollision(User $user, string $name): void
    {
        $existing = $user->tokens()->whereNull('revoked_at')->where('name', $name)->get();

        if ($existing->isEmpty()) {
            return;
        }

        $answer = $this->choice(
            "An active token named \"{$name}\" already exists for this user.",
            ['Replace it (revoke the existing one)', 'Add another'],
            0
        );

        if ($answer === 'Replace it (revoke the existing one)') {
            $existing->each(fn (Token $token) => $this->revokedAt($token));
        }
    }

    private function wizardRevoke(Collection $roster): int
    {
        $labels = $roster->values()->map(fn (Token $token) => $this->rosterChoiceLabel($token))->all();

        $answer = $this->choice('Which token should be revoked?', $labels, 0);

        $token = $roster->values()->get(array_search($answer, $labels, true));

        if (! $this->confirm("Revoke \"{$answer}\"? Its next use would be a 401.", false)) {
            $this->line('  Revocation cancelled - nothing changed.');

            return self::SUCCESS;
        }

        $this->revokedAt($token);

        $this->line("  <fg=green>✓</> Token <options=bold>{$token->id}</> revoked.");

        return self::SUCCESS;
    }

    // Create (classic non-interactive path, kept behind --name= / --user=)

    private function createToken(): int
    {
        if (! $this->bootStorage()) {
            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        if ($this->refuseGuestOrPending($user)) {
            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));

        return $this->mintToken($user, $name, self::presetFor($name));
    }

    /**
     * Issues through Token::issue (the single creation path) and prints the
     * one-time reveal. A matched agent preset prints its copy-paste setup
     * block; the classic output carries the verbatim TOKEN_WARNING.
     */
    private function mintToken(User $user, string $name, ?array $preset): int
    {
        $plaintext = Token::issue($user, $name);

        $this->line('');
        $this->line("  <fg=green>✓</> Token created for <options=bold>{$user->name}</> ({$user->email}):");
        $this->line('');
        $this->line("  <options=bold>$plaintext</>");
        $this->line('');

        if ($preset !== null) {
            $this->printSetup($preset, $plaintext);
        }

        $this->line('  <fg=yellow>●</> '.self::TOKEN_WARNING);
        $this->line('  The token is shown only this once - copy it now.');

        return self::SUCCESS;
    }

    /**
     * Prints one preset's copy-paste block (the agent presets and the wizard's
     * generic Other block) with the real endpoint and token inline.
     */
    private function printSetup(array $preset, string $plaintext): void
    {
        $this->printAppUrlNote();
        $this->line('  Copy-paste this into your agent setup:');
        $this->line('');
        $this->printBlock(self::renderBlock($preset, self::endpointUrl(config('app.url'), config('redline.path')), $plaintext));
        $this->line('');

        if ($preset['note'] !== null) {
            $this->line("  Note: {$preset['note']}");
        }

        // The operator pitfall behind most "my redline endpoint 404s" reports:
        // the route set is compiled, so an env change needs a fresh cache.
        $this->line('');
        $this->line('  <fg=yellow>●</> Run php artisan route:clear (or re-run route:cache / optimize) after changing REDLINE_* env values, or the endpoint will 404 from a stale cache.');
    }

    private function printAppUrlNote(): void
    {
        if (rtrim((string) config('app.url'), '/') !== '') {
            return;
        }

        $this->line('  <fg=yellow>●</> APP_URL is not set - replace <YOUR-APP-URL> below with your app\'s URL.');
    }

    /**
     * A multi-line copy-paste block, indented to stay aligned with the rest of
     * the command output.
     */
    private function printBlock(string $block): void
    {
        collect(explode(PHP_EOL, $block))->each(fn (string $line) => $this->line('    '.$line));
    }

    // List

    private function listTokens(): int
    {
        if (! $this->bootStorage()) {
            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->renderRoster($user->tokens()->orderBy('created_at')->get());

        return self::SUCCESS;
    }

    // Revoke

    private function revokeOne(string $id): int
    {
        if (! $this->bootStorage()) {
            return self::FAILURE;
        }

        $token = app(Token::class)->where('id', $id)->first();

        if ($token === null) {
            $this->error("No token found with id [$id].");

            return self::FAILURE;
        }

        if ($token->revoked_at !== null) {
            $this->line('  <fg=yellow>●</> Token already revoked (nothing to do).');

            return self::SUCCESS;
        }

        $this->revokedAt($token);

        $this->line("  <fg=green>✓</> Token <options=bold>$id</> revoked.");

        return self::SUCCESS;
    }

    private function revokeAll(): int
    {
        if ($this->option('user') === null) {
            $this->error('--revoke-all requires --user={email}.');

            return self::FAILURE;
        }

        if (! $this->bootStorage()) {
            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $count = 0;

        $user->tokens()->whereNull('revoked_at')->each(function (Token $token) use (&$count): void {
            $this->revokedAt($token);
            $count++;
        });

        $this->line("  <fg=green>✓</> Revoked $count token(s) for {$user->email}.");

        return self::SUCCESS;
    }

    private function revokedAt(Token $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }

    // Roster rendering (wizard choice lists and --list)

    private function renderRoster(Collection $tokens): void
    {
        $rows = $tokens->map(fn (Token $token) => [
            $token->id,
            $this->rosterName($token->name),
            $token->token_hint === '' ? '-' : '···'.$token->token_hint,
            optional($token->created_at)->format('Y-m-d') ?? '-',
            optional($token->last_used_at)->format('Y-m-d') ?? 'never',
        ])->all();

        $this->table(['ID', 'Name', 'Hint', 'Created', 'Last used'], $rows);
    }

    /**
     * The roster name column: the humanized agent label when the token name is
     * a known agent slug, the raw name otherwise.
     */
    private function rosterName(string $name): string
    {
        if ($name === '') {
            return '-';
        }

        return self::agentLabelFor($name) ?? $name;
    }

    /**
     * A roster line as one pickable label, in the documented roster format.
     */
    private function rosterChoiceLabel(Token $token): string
    {
        return sprintf(
            '%s · ···%s · created %s · last used %s',
            $this->rosterName($token->name),
            $token->token_hint === '' ? '----' : $token->token_hint,
            optional($token->created_at)->format('Y-m-d') ?? 'unknown',
            optional($token->last_used_at)->format('Y-m-d') ?? 'never',
        );
    }

    // Shared

    /**
     * Every path into minting refuses guests and pending accounts; the
     * refusal wording is contract text - do not reword.
     */
    private function refuseGuestOrPending(User $user): bool
    {
        if ($user->isGuest) {
            $this->error('Guest accounts cannot hold API tokens.');

            return true;
        }

        if ($user->isPending) {
            $this->error('Pending accounts cannot hold API tokens. Approve them first.');

            return true;
        }

        return false;
    }

    /**
     * Resolve the account from --user= or the email prompt; failures are
     * printed, null is returned.
     */
    private function resolveUser(): ?User
    {
        $email = trim((string) ($this->option('user') ?? $this->ask('Email (account to manage)')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return null;
        }

        return $this->lookupUser($email);
    }

    /**
     * The email lookup without the prompt; not found, guest and pending
     * accounts are printed and null is returned.
     */
    private function lookupUser(string $email): ?User
    {
        $user = app(User::class)->whereEmail($email)->first();

        if ($user === null) {
            $this->error("No Redline user found for [{$email}].");

            return null;
        }

        if ($this->refuseGuestOrPending($user)) {
            return null;
        }

        return $user;
    }

    /**
     * The redline connection is bound by config in HTTP contexts only, so
     * console commands must prepare it the same way the other commands do.
     */
    private function bootStorage(): bool
    {
        try {
            (new StorageProvider)->boot();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }
}
