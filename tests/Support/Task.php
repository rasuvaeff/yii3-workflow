<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

use Rasuvaeff\Yii3Workflow\SubjectIdentity;

/** Unlike Order, Doing<->Blocked is a cycle, so random walks can run arbitrarily long. */
final class Task implements SubjectIdentity
{
    private TaskStatus $status = TaskStatus::Todo;

    public function __construct(
        private readonly string $id = 't-1',
    ) {}

    #[\Override]
    public function workflowSubjectId(): string
    {
        return $this->id;
    }

    public function status(): TaskStatus
    {
        return $this->status;
    }
}
