<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\TransitionBlockerList;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A workflow with request-level replay protection.
 *
 * Applying a transition with an idempotency key already recorded for this
 * subject is a no-op — the answer to a double-submitted form or a retried HTTP
 * call. The guarantee is only as strong as the {@see TransitionLog} behind it:
 * without a persistent log, replays are detected within one request only.
 *
 * This is a decorator, not a `WorkflowInterface` implementation: that interface
 * gained methods between Symfony 6.4 and 8.x, so implementing it would tie the
 * package to one of them. Use {@see workflow()} where a raw `WorkflowInterface`
 * is required.
 *
 * @api
 */
final readonly class IdempotentWorkflow
{
    public function __construct(
        private WorkflowInterface $workflow,
        private ?TransitionLog $log = null,
        private ?IdempotencyContext $idempotency = null,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @return bool Whether the transition was applied (false = replay skipped)
     */
    public function applyOnce(object $subject, string $transitionName, ?string $idempotencyKey = null, array $context = []): bool
    {
        if ($idempotencyKey === null) {
            $this->apply($subject, $transitionName, $context);

            return true;
        }

        if (!$subject instanceof SubjectIdentity) {
            throw new \InvalidArgumentException(\sprintf(
                'An idempotency key requires the subject to implement %s, %s given',
                SubjectIdentity::class,
                $subject::class,
            ));
        }

        if ($this->log?->hasIdempotencyKey($this->name(), $subject->workflowSubjectId(), $idempotencyKey) === true) {
            return false;
        }

        $idempotency = $this->idempotency;

        if ($idempotency === null) {
            $this->apply($subject, $transitionName, $context);

            return true;
        }

        $idempotency->during(
            $idempotencyKey,
            fn(): Marking => $this->apply($subject, $transitionName, $context),
        );

        return true;
    }

    /** @param array<string, mixed> $context */
    public function apply(object $subject, string $transitionName, array $context = []): Marking
    {
        return $this->workflow->apply($subject, $transitionName, $context);
    }

    public function can(object $subject, string $transitionName): bool
    {
        return $this->workflow->can($subject, $transitionName);
    }

    /** Why a transition is unavailable — reasons come from guard listeners. */
    public function blockers(object $subject, string $transitionName): TransitionBlockerList
    {
        return $this->workflow->buildTransitionBlockerList($subject, $transitionName);
    }

    /** @return iterable<Transition> */
    public function enabledTransitions(object $subject): iterable
    {
        return $this->workflow->getEnabledTransitions($subject);
    }

    public function name(): string
    {
        return $this->workflow->getName();
    }

    public function definition(): Definition
    {
        return $this->workflow->getDefinition();
    }

    /** The wrapped workflow, for APIs that expect a raw `WorkflowInterface`. */
    public function workflow(): WorkflowInterface
    {
        return $this->workflow;
    }
}
