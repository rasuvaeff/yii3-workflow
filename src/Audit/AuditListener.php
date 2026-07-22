<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Workflow\SubjectIdentity;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Records one {@see TransitionRecord} per committed transition.
 *
 * A plain PSR-14 listener: it can be attached to the application's dispatcher
 * like any other Yii3 listener, or handed to
 * {@see \Rasuvaeff\Yii3Workflow\WorkflowEventDispatcher} so the history is kept
 * even when the application has no dispatcher bound.
 *
 * Subjects that do not implement {@see SubjectIdentity} are skipped: without an
 * id a history row cannot be attributed to anything.
 *
 * @api
 */
final readonly class AuditListener
{
    public function __construct(
        private TransitionLog $log,
        private ClockInterface $clock,
        private IdempotencyContext $idempotency,
    ) {}

    /**
     * A Petri-net transition may touch several places; the audit row keeps them
     * comma-separated rather than losing the ones that do not fit.
     *
     * @param array<array-key, mixed> $places
     */
    private function places(array $places): string
    {
        $names = [];

        foreach ($places as $place) {
            if (\is_string($place)) {
                $names[] = $place;
            }
        }

        return \implode(',', $names);
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof CompletedEvent) {
            return;
        }

        $subject = $event->getSubject();
        $transition = $event->getTransition();

        if (!$subject instanceof SubjectIdentity || $transition === null) {
            return;
        }

        $this->log->append(new TransitionRecord(
            workflow: $event->getWorkflowName(),
            subjectId: $subject->workflowSubjectId(),
            transition: $transition->getName(),
            from: $this->places($transition->getFroms()),
            to: $this->places($transition->getTos()),
            at: $this->clock->now(),
            idempotencyKey: $this->idempotency->current(),
        ));
    }
}
