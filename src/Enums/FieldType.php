<?php

namespace Zipavlin\Redline\Enums;

enum FieldType: string
{
    case STRING = 'string';
    case NUMBER = 'number';
    case PASSWORD = 'password';
    case PIN = 'pin';
}
