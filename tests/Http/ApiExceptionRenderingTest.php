<?php

use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zipavlin\Redline\Exceptions\FeatureDisabled;
use Zipavlin\Redline\Exceptions\ForbiddenAction;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;

/**
 * The Actions layer's domain exceptions are HTTP exceptions by construction:
 * ForbiddenAction -> 403 with its own message, FeatureDisabled -> 403 "Feature
 * disabled.", a wrong parent -> the bare 404 - every body byte-identical to the
 * `abort()` envelopes the controllers used to throw themselves, with no
 * per-controller try/catch and no renderer registration.
 */
beforeEach(fn () => ensureRedlineDirectories());

function apiExceptionRequest(): Request
{
    $request = Request::create('/redline/api/report/x', 'GET');
    $request->headers->set('Accept', 'application/json');

    return $request;
}

/**
 * A report row (the model) authored by the given user array.
 */
function reportFixtureBy(array $owner, array $overrides = []): Report
{
    return app(Report::class)->create([
        'author_id' => $owner['id'],
        'url' => 'https://example.com/x',
        'location' => '/x',
        'body' => 'Original.',
        'status' => 'new',
        ...$overrides,
    ]);
}

// ---------------------------------------------------------------------------
// Over the wire - every reachable domain exception, exact envelope
// ---------------------------------------------------------------------------

it('renders ForbiddenAction as the API 403 envelope with its own message', function () {
    $owner = makeUser('reporter');
    $report = reportFixtureBy($owner);

    // A developer must not rewrite someone else's body (Operation::EDIT
    // is author-only) - ForbiddenAction leaves the action layer.
    $this->patchJson("/redline/api/report/{$report->id}", ['body' => 'hijack'], authHeaders('developer'))
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden.']);
});

it('renders FeatureDisabled as the API 403 Feature disabled. envelope', function () {
    config(['redline.features' => ['screenshots']]);

    $owner = makeUser('reporter');
    $report = reportFixtureBy($owner);

    $this->postJson("/redline/api/report/{$report->id}/comment", ['body' => 'text'], authHeaders('developer'))
        ->assertStatus(403)
        ->assertJson(['message' => 'Feature disabled.']);
});

it('renders an invalid status transition in the same 403 envelope', function () {
    $owner = makeUser('reporter');
    $report = reportFixtureBy($owner);

    $this->patchJson(
        "/redline/api/report/{$report->id}/status",
        ['status' => 'progress'],
        authHeaders('developer'),
    )->assertStatus(403)
        ->assertJson(['message' => 'Invalid status transition.']);
});

it('renders a wrong-parent row as the bare 404', function () {
    $owner = makeUser('reporter');
    $correctReport = reportFixtureBy($owner);

    $wrongReport = reportFixtureBy($owner, [
        'url' => 'https://example.com/y',
        'location' => '/y',
        'body' => 'Wrong parent.',
    ]);

    $comment = app(Comment::class)->create([
        'report_id' => $correctReport->id,
        'author_id' => $owner['id'],
        'body' => 'Mine.',
    ]);

    $this->patchJson(
        "/redline/api/report/{$wrongReport->id}/comment/{$comment->id}",
        ['body' => 'hijack'],
        ['Authorization' => 'Bearer '.makeAuthToken($owner)],
    )->assertStatus(404)
        ->assertJson(['message' => '']);
});

// ---------------------------------------------------------------------------
// Byte parity - the renderer's output equals the abort() envelope it replaced
// ---------------------------------------------------------------------------

it('produces byte-identical bodies to the abort() envelopes it replaced', function () {
    // The production envelope ({message} only); the test default's debug
    // extras (exception/file/line/trace) carry the throw site, which is
    // allowed to move - the suite's previous 403/404 assertions only ever
    // pinned selector keys like `message`.
    config(['app.debug' => false]);

    $handler = app(LaravelExceptionHandler::class);
    $request = apiExceptionRequest();

    $central = fn (Throwable $exception) => $handler->render($request, $exception)->getContent();
    $aborted = fn (HttpException|NotFoundHttpException $wrapped) => $handler->render($request, $wrapped)->getContent();

    expect($central(new ForbiddenAction))
        ->toBe($aborted(new HttpException(403, 'Forbidden.')))
        ->and($central(new FeatureDisabled))
        ->toBe($aborted(new HttpException(403, 'Feature disabled.')))
        ->and($central(new NotFoundHttpException))
        ->toBe($aborted(new NotFoundHttpException));
});
