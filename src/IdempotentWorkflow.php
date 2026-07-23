<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Yii3Workflow\Audit\DuplicateIdempotencyKey;
use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionReplayed;
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
 * call.
 *
 * Two lines of defence: a cheap pre-flight lookup, and the log's own uniqueness
 * constraint, which decides the race when two concurrent requests pass the
 * lookup together. The loser's `append()` throws
 * {@see DuplicateIdempotencyKey} and `applyOnce()` reports `false`. The subject
 * has already been mutated in memory by then — run the call inside a
 * transaction, or discard the object, so a lost race cannot be persisted.
 *
 * The guarantee is only as strong as the wiring behind it, so a key is refused
 * outright instead of silently doing nothing when the wiring cannot support it:
 * without a bound {@see TransitionLog}, without an {@see IdempotencyContext}
 * (the key could never reach the log), and — detected after the fact — when the
 * workflow's dispatcher has no {@see \Rasuvaeff\Yii3Workflow\Audit\AuditListener}
 * sharing this log and context, so the transition completed but the key was
 * never recorded. {@see WorkflowFactory} produces consistent wiring; assemble
 * the pieces manually only if they share the same instances.
 *
 * A key is scoped to (workflow, subject), not to a transition: reusing a key on
 * the same subject with a different transition is reported as a replay
 * (`false`), so keys must be unique per operation.
 *
 * Every skipped replay is also dispatched as a {@see TransitionReplayed} event
 * when a PSR-14 dispatcher is bound, so replays can be counted and logged
 * instead of disappearing into a `false` return value.
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
        private ?EventDispatcherInterface $dispatcher = null,
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

        if ($this->log === null) {
            throw new \LogicException(
                'An idempotency key was supplied but no TransitionLog is bound, so replay protection '
                . 'would silently do nothing. Bind a TransitionLog implementation or drop the key.',
            );
        }

        if ($this->idempotency === null) {
            throw new \LogicException(
                'An idempotency key was supplied but no IdempotencyContext is bound, so the key could '
                . 'never reach the transition log. Build the workflow via WorkflowFactory, or pass the '
                . 'same IdempotencyContext instance the AuditListener uses.',
            );
        }

        if (!$subject instanceof SubjectIdentity) {
            throw new \InvalidArgumentException(\sprintf(
                'An idempotency key requires the subject to implement %s, %s given',
                SubjectIdentity::class,
                $subject::class,
            ));
        }

        if ($this->log->hasIdempotencyKey($this->name(), $subject->workflowSubjectId(), $idempotencyKey)) {
            $this->replayed($subject, $transitionName, $idempotencyKey, storageDecided: false);

            return false;
        }

        try {
            $this->idempotency->during(
                $idempotencyKey,
                fn(): Marking => $this->apply($subject, $transitionName, $context),
            );
        } catch (DuplicateIdempotencyKey) {
            // A concurrent request recorded the same key between our lookup and
            // our write. The subject is dirty in memory by now; the caller's
            // transaction is what keeps that from reaching storage.
            $this->replayed($subject, $transitionName, $idempotencyKey, storageDecided: true);

            return false;
        }

        if (!$this->log->hasIdempotencyKey($this->name(), $subject->workflowSubjectId(), $idempotencyKey)) {
            throw new \LogicException(
                'The transition completed but its idempotency key was never recorded: no AuditListener '
                . 'sharing this TransitionLog and IdempotencyContext is attached to the workflow. Build '
                . 'the workflow via WorkflowFactory so the wiring is consistent.',
            );
        }

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

    private function replayed(SubjectIdentity $subject, string $transition, string $key, bool $storageDecided): void
    {
        $this->dispatcher?->dispatch(new TransitionReplayed(
            workflow: $this->name(),
            subjectId: $subject->workflowSubjectId(),
            transition: $transition,
            idempotencyKey: $key,
            storageDecided: $storageDecided,
        ));
    }
}
