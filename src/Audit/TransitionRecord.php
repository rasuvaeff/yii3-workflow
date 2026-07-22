<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

/**
 * One committed transition: the row an audit table stores.
 *
 * `symfony/workflow` keeps only the current marking, so the history is
 * assembled here from the `workflow.completed` event.
 *
 * @api
 */
final readonly class TransitionRecord
{
    public function __construct(
        public string $workflow,
        public string $subjectId,
        public string $transition,
        public string $from,
        public string $to,
        public \DateTimeImmutable $at,
        public ?string $idempotencyKey = null,
    ) {}

    /** @return array{workflow: string, subjectId: string, transition: string, from: string, to: string, at: string, idempotencyKey: string|null} */
    public function toArray(): array
    {
        return [
            'workflow' => $this->workflow,
            'subjectId' => $this->subjectId,
            'transition' => $this->transition,
            'from' => $this->from,
            'to' => $this->to,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'idempotencyKey' => $this->idempotencyKey,
        ];
    }
}
