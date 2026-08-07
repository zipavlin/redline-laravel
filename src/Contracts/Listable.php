<?php

namespace Zipavlin\Redline\Contracts;

interface Listable
{
    public static function title(): string;

    public static function position(): int;
}
