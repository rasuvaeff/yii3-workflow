<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * One edge of the task workflow graph, doubling as a model-based test
 * command: the model is the expected {@see TaskStatus}, and running the
 * command drives the real system (a {@see TaskWorkflowHarness}) through the
 * same transition.
 */
final readonly class ApplyTransitionCommand implements Command
{
    public function __construct(
        private TaskStatus $from,
        private string $transitionName,
        private TaskStatus $to,
    ) {}

    public function transitionName(): string
    {
        return $this->transitionName;
    }

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return $model === $this->from;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        return $this->to;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof TaskWorkflowHarness);

        $system->workflow->apply($system->task, $this->transitionName);
        $system->applied[] = $this->transitionName;

        return $system->task->status();
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        return $result === $this->to;
    }

    #[\Override]
    public function __toString(): string
    {
        return \sprintf('%s (%s -> %s)', $this->transitionName, $this->from->value, $this->to->value);
    }
}
