<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Zipavlin\Redline\Commands\Concerns\AgentInstructions;
use Zipavlin\Redline\Models\Token;
use Zipavlin\Redline\Models\User;

/**
 * `redline:mcp` - MCP API token lifecycle + agent setup wizard. Contract
 * requirements: guest and pending users never receive a token; the plaintext
 * is printed exactly once and never stored reversibly; --revoke / --revoke-all
 * soft-revoke (a later use is 401); `password_epoch` never leaks from user
 * serialization.
 */
function redlineAgentChoices(): array
{
    return collect(AgentInstructions::agentPresets())
        ->map(fn (array $preset) => $preset['label'])
        ->prepend('Other (type a custom name)')
        ->values()
        ->all();
}

function redlineFlatOutput(string $output): string
{
    return str_replace(["\r", "\n", ' '], '', $output);
}

function redlineRosterChoiceLabel(string $name, Token $row): string
{
    return sprintf(
        '%s · ···%s · created %s · last used %s',
        AgentInstructions::agentLabelFor($name) ?? $name,
        $row->token_hint,
        $row->created_at->format('Y-m-d'),
        'never',
    );
}

it('registers under the new redline:mcp signature and not the old one', function () {
    $signatures = collect(array_keys(Artisan::all()));

    expect($signatures)->toContain('redline:mcp')
        ->and($signatures)->not->toContain('redline:token');
});

it('creates a token for the selected user and prints the plaintext once', function () {
    $user = makeTokenOwner();

    $exit = Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'agent']);

    expect($exit)->toBe(0);

    $output = Artisan::output();
    expect($output)
        ->toContain('Token created for')
        ->toContain("This token acts with the user's full permissions - treat it as a password; it is NOT invalidated by logout; it IS invalidated by that user's password change.");

    preg_match('/rlm_[0-9a-f]{40}/', $output, $matches);
    $plaintext = $matches[0] ?? null;

    expect($plaintext)->toBeString();
    expect(substr_count($output, $plaintext))->toBe(1);

    $row = app(Token::class)->where('token_hash', Token::hash($plaintext))->first();

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('agent')
        ->and($row->user_id)->toBe($user->id)
        ->and($row->last_used_at)->toBeNull()
        ->and($row->security_epoch)->toBe(0);
});

it('records the last four characters of the plaintext as an identification hint', function () {
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'agent']);

    preg_match('/rlm_[0-9a-f]{40}/', Artisan::output(), $matches);
    $row = app(Token::class)->where('user_id', $user->id)->first();

    expect($row->token_hint)->toBe(substr($matches[0], -4))
        ->and(strlen($row->token_hint))->toBe(4);
});

it('keeps token_hint out of mass assignment - the hint is never a credential', function () {
    $token = new Token(['name' => 'attempted', 'token_hint' => '9999']);

    expect($token->token_hint)->toBeNull()
        ->and($token->name)->toBe('attempted');
});

it('also takes the account email from a prompt', function () {
    $user = makeTokenOwner();

    $this->artisan('redline:mcp', ['--name' => 'agent'])
        ->expectsQuestion('Email (account to manage)', $user->email)
        ->assertExitCode(0)
        ->expectsOutputToContain('Token created for');

    expect(app(Token::class)->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses to issue a token to a pending user', function () {
    $user = makeTokenOwner();

    app(User::class)->where('id', $user->id)->update(['status' => 'pending']);

    $this->artisan('redline:mcp', ['--user' => $user->refresh()->email])
        ->assertExitCode(1)
        ->expectsOutputToContain('Pending accounts cannot hold API tokens');

    expect(app(Token::class)->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses to issue a token to a guest', function () {
    $guest = app(User::class)->create([
        'name' => 'Guest',
        'email' => 'guest'.Str::uuid().'@redline.local',
        'role' => 'guest',
    ]);

    $this->artisan('redline:mcp', ['--user' => $guest->email])
        ->assertExitCode(1)
        ->expectsOutputToContain('Guest accounts cannot hold API tokens');

    expect(app(Token::class)->where('user_id', $guest->id)->exists())->toBeFalse();
});

it('prints the matching agent preset block for --name=<slug> with the real token inline', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'claude-code']);

    $flat = redlineFlatOutput(Artisan::output());
    preg_match('/rlm_[0-9a-f]{40}/', $flat, $matches);

    expect($matches[0])->toBeString()
        ->and($flat)->toContain('--transporthttphttps://staging.example.com/redline/api/mcp')
        ->and($flat)->toContain('Bearer'.$matches[0])
        ->not->toContain('rlm_xxx');
});

it('keeps the classic output for an unknown --name without an agent block', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'agent-x']);

    $flat = redlineFlatOutput(Artisan::output());

    expect($flat)->toContain("Thistokenactswiththeuser'sfullpermissions")
        ->not->toContain('claudemcpaddredline')
        ->not->toContain('Copy-pastethisinto');
});

it('prints a URL placeholder plus a note when APP_URL is unset', function () {
    config(['app.url' => '']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'claude-code']);

    $output = Artisan::output();

    expect($output)
        ->toContain('APP_URL is not set')
        ->toContain('<YOUR-APP-URL>')
        ->not->toContain('https://staging.example.com');
});

it('prints the verified Cursor preset: export line, mcpServers file shape, the 401 caveat and no CLI add command', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'cursor']);

    $flat = redlineFlatOutput(Artisan::output());
    preg_match('/rlm_[0-9a-f]{40}/', $flat, $matches);

    expect($matches[0])->toBeString()
        ->and($flat)
        ->toContain('exportREDLINE_MCP_TOKEN='.$matches[0])
        // The file shape has no `type` key and no CLI add command at all.
        ->toContain('"mcpServers":{"redline":{"url":"https://staging.example.com/redline/api/mcp"')
        ->toContain('${env:REDLINE_MCP_TOKEN}')
        ->toContain('putthetokenliterallyintheuser-levelfile')
        ->not->toContain('mcpadd');
});

it('prints the verified Gemini CLI preset: the httpUrl key, the user-scope CLI alt and the export line', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'gemini']);

    $flat = redlineFlatOutput(Artisan::output());
    preg_match('/rlm_[0-9a-f]{40}/', $flat, $matches);

    expect($matches[0])->toBeString()
        ->and($flat)
        ->toContain('exportREDLINE_MCP_TOKEN='.$matches[0])
        // Assert the key itself: it is httpUrl, not url (verified 2026-10).
        ->toContain('"mcpServers":{"redline":{"httpUrl":"https://staging.example.com/redline/api/mcp"')
        ->toContain('${REDLINE_MCP_TOKEN}')
        ->toContain('geminimcpadd--transporthttp--scopeuser')
        ->not->toContain('"redline":{"url":');
});

it('prints the verified VS Code preset: the inputs prompt and the top-level servers key', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'vscode']);

    $flat = redlineFlatOutput(Artisan::output());
    preg_match('/rlm_[0-9a-f]{40}/', $flat, $matches);

    expect($matches[0])->toBeString()
        ->and($flat)
        ->toContain('exportREDLINE_MCP_TOKEN='.$matches[0])
        // The top-level key is `servers`, not `mcpServers` (verified 2026-10).
        ->toContain('"servers":{"redline":{"type":"http","url":"https://staging.example.com/redline/api/mcp"')
        // The token in the file config comes from the inputs promptString.
        ->toContain('${input:redline-mcp-token}')
        ->toContain('code--add-mcp');
});

it('prints the placeholder URL and the real-token reveal for the file-shaped presets when APP_URL is unset', function () {
    config(['app.url' => '']);
    $user = makeTokenOwner();

    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'vscode']);

    $flat = redlineFlatOutput(Artisan::output());
    preg_match('/rlm_[0-9a-f]{40}/', $flat, $matches);

    expect($matches[0])->toBeString()
        ->and($flat)
        ->toContain('<YOUR-APP-URL>')
        ->not->toContain('https://staging.example.com')
        // The plaintext stays discoverable through the CLI alternative.
        ->toContain('exportREDLINE_MCP_TOKEN='.$matches[0]);
});

it('runs the wizard on a bare invocation: user select, agent select, mint with block', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$user->email} (developer)", ["{$user->email} (developer)"], true)
        ->expectsChoice('Which agent will use this token?', 'OpenCode', redlineAgentChoices(), true)
        ->assertExitCode(0)
        ->expectsOutputToContain('Token created for');

    $row = app(Token::class)->where('user_id', $user->id)->first();

    expect($row->name)->toBe('opencode')
        ->and(strlen($row->token_hint))->toBe(4);
});

it('hides guest and pending accounts from the wizard user list', function () {
    $approved = makeTokenOwner();

    app(User::class)->create([
        'name' => 'Guest',
        'email' => 'guest'.Str::uuid().'@redline.local',
        'role' => 'guest',
    ]);
    app(User::class)->create([
        'name' => 'Pending',
        'email' => 'pending'.Str::uuid().'@redline.local',
        'role' => 'reporter',
        'status' => 'pending',
    ]);

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$approved->email} (developer)", ["{$approved->email} (developer)"], true)
        ->expectsChoice('Which agent will use this token?', 'Claude Code', redlineAgentChoices(), true)
        ->assertExitCode(0);

    expect(app(Token::class)->where('user_id', $approved->id)->count())->toBe(1);
});

it('falls back to a typed filter when the approved pool exceeds 25 users', function () {
    $user = null;

    for ($i = 0; $i < 26; $i++) {
        $user = makeTokenOwner();
    }

    $this->artisan('redline:mcp')
        ->expectsQuestion('26 approved users. Type a name or email fragment to filter (or an exact email):', $user->email)
        ->expectsChoice('Which agent will use this token?', 'Claude Code', redlineAgentChoices(), true)
        ->assertExitCode(0);

    expect(app(Token::class)->where('user_id', $user->id)->count())->toBe(1);
});

it('wizard typed lookup keeps the verbatim guest refusal', function () {
    $user = null;

    for ($i = 0; $i < 26; $i++) {
        $user = makeTokenOwner();
    }

    $guest = app(User::class)->create([
        'name' => 'Guest',
        'email' => 'guest'.Str::uuid().'@redline.local',
        'role' => 'guest',
    ]);

    $this->artisan('redline:mcp')
        ->expectsQuestion('26 approved users. Type a name or email fragment to filter (or an exact email):', $guest->email)
        ->assertExitCode(1)
        ->expectsOutputToContain('Guest accounts cannot hold API tokens');

    expect(app(Token::class)->where('user_id', $guest->id)->exists())->toBeFalse();
});

it('offers the roster and revokes the picked token', function () {
    $user = makeTokenOwner();
    tokenFor($user, 'claude-code');
    $row = $user->tokens()->first();

    $label = redlineRosterChoiceLabel('claude-code', $row);

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$user->email} (developer)", ["{$user->email} (developer)"], true)
        ->expectsChoice('The user has 1 active token(s). What next?', 'Revoke a token', ['Add new token', 'Revoke a token'], true)
        ->expectsChoice('Which token should be revoked?', $label, [$label], true)
        ->expectsConfirmation("Revoke \"{$label}\"? Its next use would be a 401.", 'yes')
        ->assertExitCode(0);

    expect($row->refresh()->revoked_at)->not->toBeNull();
});

it('cancelling a wizard revoke changes nothing', function () {
    $user = makeTokenOwner();
    tokenFor($user, 'claude-code');
    $row = $user->tokens()->first();

    $label = redlineRosterChoiceLabel('claude-code', $row);

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$user->email} (developer)", ["{$user->email} (developer)"], true)
        ->expectsChoice('The user has 1 active token(s). What next?', 'Revoke a token', ['Add new token', 'Revoke a token'], true)
        ->expectsChoice('Which token should be revoked?', $label, [$label], true)
        ->expectsConfirmation("Revoke \"{$label}\"? Its next use would be a 401.", 'no')
        ->assertExitCode(0);

    expect($row->refresh()->revoked_at)->toBeNull();
});

it('asks replace-or-add on a same-name collision and revokes on replace', function () {
    $user = makeTokenOwner();
    tokenFor($user, 'codex');
    $row = $user->tokens()->first();

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$user->email} (developer)", ["{$user->email} (developer)"], true)
        ->expectsChoice('The user has 1 active token(s). What next?', 'Add new token', ['Add new token', 'Revoke a token'], true)
        ->expectsChoice('Which agent will use this token?', 'Codex', redlineAgentChoices(), true)
        ->expectsChoice('An active token named "codex" already exists for this user.', 'Replace it (revoke the existing one)', ['Replace it (revoke the existing one)', 'Add another'], true)
        ->assertExitCode(0);

    expect($row->refresh()->revoked_at)->not->toBeNull();

    $active = $user->tokens()->whereNull('revoked_at')->get();

    expect($active)->toHaveCount(1)
        ->and($active[0]->name)->toBe('codex');
});

it('adds an extra token on a same-name collision when "add another" is picked', function () {
    $user = makeTokenOwner();
    tokenFor($user, 'codex');
    $row = $user->tokens()->first();

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$user->email} (developer)", ["{$user->email} (developer)"], true)
        ->expectsChoice('The user has 1 active token(s). What next?', 'Add new token', ['Add new token', 'Revoke a token'], true)
        ->expectsChoice('Which agent will use this token?', 'Codex', redlineAgentChoices(), true)
        ->expectsChoice('An active token named "codex" already exists for this user.', 'Add another', ['Replace it (revoke the existing one)', 'Add another'], true)
        ->assertExitCode(0);

    expect($row->refresh()->revoked_at)->toBeNull();

    $active = $user->tokens()->whereNull('revoked_at')->get();

    expect($active)->toHaveCount(2)
        ->and($active->where('name', 'codex'))->toHaveCount(2);
});

it('prints the generic setup block for the wizard Other choice with a typed name', function () {
    config(['app.url' => 'https://staging.example.com']);
    $user = makeTokenOwner();

    $this->artisan('redline:mcp')
        ->expectsChoice('Which account gets the token?', "{$user->email} (developer)", ["{$user->email} (developer)"], true)
        ->expectsChoice('Which agent will use this token?', 'Other (type a custom name)', redlineAgentChoices(), true)
        ->expectsQuestion('Name for the token (e.g. what will use it):', 'my-templater')
        ->assertExitCode(0)
        // Substring expectations share doWrite chunks eagerly in Mockery; keep
        // this probe minimal and anchored inside the block chunk.
        ->expectsOutputToContain('"Authorization"')
        ->expectsOutputToContain('Bearer')
        ->expectsOutputToContain('route:clear');

    expect(app(Token::class)->where('user_id', $user->id)->first()->name)->toBe('my-templater');
});

it('lists tokens with the hint and humanized agent label', function () {
    $user = makeTokenOwner();
    $plaintext = tokenFor($user, 'claude-code');
    $row = app(Token::class)->where('user_id', $user->id)->first();

    Artisan::call('redline:mcp', ['--list' => true, '--user' => $user->email]);

    $output = Artisan::output();
    $flat = redlineFlatOutput($output);

    expect($flat)
        ->toContain('ClaudeCode')
        ->toContain('···'.$row->token_hint)
        ->toContain($row->created_at->format('Y-m-d'))
        ->not->toContain(Token::hash($plaintext))
        // The plaintext is never serialized, in the roster either.
        ->not->toContain($plaintext);
});

it('lists a real created timestamp for a freshly issued token', function () {
    $user = makeTokenOwner();

    // Create through the command itself (the production path), then list:
    // the issued row stamps created_at, so the table shows the issue date -
    // never the '-' of an unwritten column.
    Artisan::call('redline:mcp', ['--user' => $user->email, '--name' => 'fresh']);
    Artisan::call('redline:mcp', ['--list' => true, '--user' => $user->email]);

    $row = app(Token::class)->where('user_id', $user->id)->first();

    expect($row->created_at)->toBeInstanceOf(CarbonInterface::class);
    expect(Artisan::output())->toContain($row->created_at->format('Y-m-d'));
});

it('soft-revokes a single token via --revoke, making later uses 401', function () {
    ['token' => $plaintext] = makeApiToken();
    $row = app(Token::class)->where('token_hash', Token::hash($plaintext))->first();

    $this->artisan('redline:mcp', ['--revoke' => $row->id])->assertExitCode(0);

    expect($row->refresh()->revoked_at)->not->toBeNull();

    $this->postJson('/redline/api/mcp', initializeMcpBody(), [
        'Authorization' => 'Bearer '.$plaintext,
    ])->assertUnauthorized();
});

it('revokes every token of a user via --revoke-all --user=', function () {
    $user = makeTokenOwner();
    tokenFor($user);
    tokenFor($user);

    $this->artisan('redline:mcp', ['--revoke-all' => true, '--user' => $user->email])->assertExitCode(0);

    expect(app(Token::class)->where('user_id', $user->id)->whereNull('revoked_at')->exists())->toBeFalse();
});

it('requires --user= for --revoke-all', function () {
    $this->artisan('redline:mcp', ['--revoke-all' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('--revoke-all requires --user=');
});

it('does not serialize password_epoch on user rows or token_hash on token rows', function () {
    ['user' => $user, 'token' => $plaintext] = makeApiToken();

    // A protected read via the rlm_ bearer: user rows leaving the API stay
    // free of the epoch counter (and token_version); token rows stay free of
    // the hash.
    $rows = $this->get('/redline/api/user', ['Authorization' => 'Bearer '.$plaintext])
        ->assertOk()
        ->json('data');

    expect($rows)->toBeArray()->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('password_epoch');
    }

    $tokenRow = app(Token::class)->where('token_hash', Token::hash($plaintext))->first()->toArray();

    expect($tokenRow)->not->toHaveKey('token_hash');
});
