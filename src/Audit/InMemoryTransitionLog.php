<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

/**
 * In-process {@see TransitionLog} for tests and single-request scripts. Nothing
 * survives the request — bind a persistent implementation in production.
 *
 * @api
 */
final class InMemoryTransitionLog implements TransitionLog
{
    /** @var list<TransitionRecord> */
    private array $records = [];

    #[\Override]
    public function append(TransitionRecord $record): void
    {
        if ($record->idempotencyKey !== null
            && $this->hasIdempotencyKey($record->workflow, $record->subjectId, $record->idempotencyKey)
        ) {
            throw new DuplicateIdempotencyKey($record->workflow, $record->subjectId, $record->idempotencyKey);
        }

        $this->records[] = $record;
    }

    /** @return list<TransitionRecord> */
    #[\Override]
    public function forSubject(string $workflow, string $subjectId): array
    {
        return \array_values(\array_filter(
            $this->records,
            static fn(TransitionRecord $record): bool => $record->workflow === $workflow
                && $record->subjectId === $subjectId,
        ));
    }

    #[\Override]
    public function hasIdempotencyKey(string $workflow, string $subjectId, string $key): bool
    {
        foreach ($this->forSubject($workflow, $subjectId) as $record) {
            if ($record->idempotencyKey === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return list<TransitionRecord> */
    public function all(): array
    {
        return $this->records;
    }
}
