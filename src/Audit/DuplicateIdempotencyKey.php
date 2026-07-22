<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

/**
 * Thrown by {@see TransitionLog::append()} when a record with this
 * (workflow, subject, idempotency key) triple already exists.
 *
 * This is what turns replay protection from a check into an invariant: a
 * storage backend enforces uniqueness, so two concurrent requests carrying the
 * same key cannot both win the check-then-act race — the loser's `append()`
 * fails and {@see \Rasuvaeff\Yii3Workflow\IdempotentWorkflow::applyOnce()}
 * reports the call as a replay.
 *
 * @api
 */
final class DuplicateIdempotencyKey extends \RuntimeException
{
    public function __construct(
        public readonly string $workflow,
        public readonly string $subjectId,
        public readonly string $idempotencyKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Transition with idempotency key "%s" is already recorded for subject "%s" of workflow "%s"',
                $idempotencyKey,
                $subjectId,
                $workflow,
            ),
            previous: $previous,
        );
    }
}
