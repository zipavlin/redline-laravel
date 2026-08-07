<?php

namespace Zipavlin\Redline\Enums;

/**
 * The parameter groups a query grammar endpoint can accept - filter[],
 * include[], sort[], paginate[], aggregate[], group_by[]. Used to narrow what
 * an endpoint accepts (ResourceQuery::use()) and to validate which
 * QueryOperator cases make sense in which group (QueryOperator::validFor()).
 */
enum QueryCapability
{
    case FILTER;
    case INCLUDE;
    case SORT;
    case PAGINATE;
    case AGGREGATE;
    case GROUP;
}
