<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\IdempotentWorkflow;

/** System under test for {@see ApplyTransitionCommand}: a fresh task + workflow per StateMachine::check() run. */
final class TaskWorkflowHarness
{
    /** @var list<string> Transition names actually applied, in order. */
    public array $applied = [];

    public function __construct(
        public readonly Task $task,
        public readonly IdempotentWorkflow $workflow,
        public readonly InMemoryTransitionLog $log,
    ) {}
}
