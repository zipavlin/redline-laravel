<?php

namespace Zipavlin\Redline\Enums;

enum Operation
{
    case CREATE;
    case UPDATE;
    case EDIT;
    case DELETE;
    case VIEW;
    case LIST;
}
