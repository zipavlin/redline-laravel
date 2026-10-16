<?php

namespace Zipavlin\Redline\Exceptions;

/**
 * A mutation behind a Feature that the host has not enabled (still 403 on
 * HTTP - see ForbiddenAction for why the message vocabulary is fixed).
 */
final class FeatureDisabled extends ForbiddenAction
{
    public function __construct()
    {
        parent::__construct('Feature disabled.');
    }
}
