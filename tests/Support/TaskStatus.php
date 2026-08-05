<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Blocked = 'blocked';
    case Done = 'done';
}
