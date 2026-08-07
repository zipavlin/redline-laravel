<?php

namespace Zipavlin\Redline\Enums;

enum Feature: string
{
    case SCREENSHOTS = 'screenshots';
    case VIDEOS = 'videos';
    case FILES = 'files';
    case COMMENTS = 'comments';
    case MENTIONS = 'mentions';
}
