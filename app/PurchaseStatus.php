<?php

namespace App;

enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';
}
