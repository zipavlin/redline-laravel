<?php

namespace Zipavlin\Redline\Enums;

enum Role: string
{
    case GUEST = 'guest';
    case REPORTER = 'reporter';
    case DEVELOPER = 'developer';
    case ADMIN = 'admin';
}
