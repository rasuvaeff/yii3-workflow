<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}
