<?php

use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Http\Query\FilterField;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->devHeaders = authHeaders('developer');
});

// ---------------------------------------------------------------------------
// The relatedFilters allowlist is the security boundary for relation-attribute
// filters. Pass 2 makes User::querySchema() expose email/role/status for its
// own admin endpoint; these tests assert that does NOT widen what's reachable
// through Report's `author` relation on the all-roles /report endpoint.
// ---------------------------------------------------------------------------

it('constructing a delegating FilterField without a relatedFilters allowlist throws', function () {
    expect(fn () => new FilterField(
        operators: [QueryOperator::EQ],
        relation: 'user',
        relatedSchema: fn () => new QuerySchema(filters: [], sortable: [], includable: []),
    ))->toThrow(InvalidArgumentException::class);
});

it('allows filter[author.name] because Report opts name into its relatedFilters', function () {
    $match = makeUser('reporter');
    app(User::class)->find($match['id'])->update(['name' => 'Ziggy Stardust']);
    $other = makeUser('reporter');

    app(Report::class)->create(['author_id' => $match['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new']);
    app(Report::class)->create(['author_id' => $other['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'b', 'status' => 'new']);

    $body = $this->getJson('/redline/api/report?filter[author.name:like]=Ziggy', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1);
});

it('rejects filter[author.email] even though User::querySchema() now declares an email filter', function () {
    // Regression guard for the Pass 2 oracle: User exposing `email` for /user must
    // not make `filter[author.email:like]` reachable on /report. The relatedFilters
    // allowlist (['name']) is what stops it - proven by User genuinely having `email`.
    expect(User::querySchema()->filters)->toHaveKey('email');

    $this->getJson('/redline/api/report?filter[author.email:like]=%40example.com', $this->devHeaders)
        ->assertStatus(422);
});

it('rejects filter[author.role] and filter[author.status] the same way', function () {
    $this->getJson('/redline/api/report?filter[author.role]=admin', $this->devHeaders)->assertStatus(422);
    $this->getJson('/redline/api/report?filter[author.status]=approved', $this->devHeaders)->assertStatus(422);
});

it('the same email attribute IS filterable on User\'s own admin endpoint', function () {
    app(User::class)->create(['name' => 'Needle', 'email' => 'needle@haystack.test', 'role' => 'reporter']);

    $admin = ['Authorization' => 'Bearer '.makeAuthToken(makeUser('admin'))];
    $body = $this->getJson('/redline/api/user?filter[email:like]=haystack&include[]=email', $admin)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['email'])->toBe('needle@haystack.test');
});
