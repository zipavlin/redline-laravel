<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\Request;
use Zipavlin\Redline\Models\User;

/**
 * The redline API controllers' shared plumbing: how the authenticated redline
 * user is resolved. Every operation gate lives in the Actions layer
 * (see Actions\Action) and the domain exceptions it throws are rendered for
 * the whole API group in one place (RedlineServiceProvider).
 */
class Controller extends \Illuminate\Routing\Controller
{
    protected function authUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }
}
