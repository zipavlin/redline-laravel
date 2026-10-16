<?php

namespace Zipavlin\Redline\Actions;

use Illuminate\Http\Request;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Exceptions\FeatureDisabled;
use Zipavlin\Redline\Exceptions\ForbiddenAction;
use Zipavlin\Redline\Exceptions\ParentMismatch;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Models\Video;

/**
 * Base of the Actions layer - one class per model (Report, Comment,
 * Screenshot, Video, Member, User), shared verbatim by the HTTP controllers
 * and the MCP tools (see the tool-facing error mapping in Mcp\Concerns).
 *
 * The layer is HTTP-agnostic: its authorization and build-time failures come
 * back as package domain exceptions (ForbiddenAction, FeatureDisabled,
 * ParentMismatch - see Exceptions/) which the redline API group renders in one
 * place (RedlineServiceProvider) and a tool maps to its own error vocabulary.
 * Laravel ValidationException (request validation, the query grammar) renders
 * through Laravel's own default path.
 */
abstract class Action
{
    /**
     * The caller's role gate every operation runs before it mutates anything.
     * A null user (no resolved identity) is treated exactly like one without
     * the operation - the request never reaches the model layer.
     */
    protected function assertAllowed(?User $user, Operation $operation, null|Comment|Report $model = null): void
    {
        if ($user === null || ! $user->can($operation, $model)) {
            throw new ForbiddenAction;
        }
    }

    /**
     * The host's enabled-features gate; a mutation behind a feature the host
     * has not configured is refused before any authorization or validation.
     */
    protected function assertFeature(Feature $feature): void
    {
        if (! in_array($feature, Feature::enabled(), true)) {
            throw new FeatureDisabled;
        }
    }

    /**
     * A row addressed through the wrong parent row (a comment PATCH whose
     * report path segment does not own the comment - a pure routing-shape
     * failure, distinct from an authorization refusal).
     */
    protected function assertRelationship(Report|Screenshot $parent, Comment|Screenshot|Video $child): void
    {
        if ($child->report_id !== $parent->id) {
            throw new ParentMismatch;
        }
    }

    /**
     * The redline user behind a grammar-driven request, or null. The grammar
     * reads `$request->user()` for per-role fields; only the redline User
     * implementation answers the operation gates, so anything else is
     * handled like no identity at all (null allows nothing).
     */
    protected function requestUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
