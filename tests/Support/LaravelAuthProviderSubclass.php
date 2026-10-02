<?php

namespace Zipavlin\Redline\Tests\Support;

use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;

/**
 * A LaravelAuthProvider subclass used to prove the create block is
 * subclass-aware (is_a with $allow_string) rather than an exact class check.
 */
class LaravelAuthProviderSubclass extends LaravelAuthProvider {}
