<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

/**
 * Dispatched to the application's PSR-14 dispatcher when
 * {@see \Rasuvaeff\Yii3Workflow\IdempotentWorkflow::applyOnce()} skips a
 * replay, so replays can be counted and logged instead of disappearing into a
 * `false` return value.
 *
 * `storageDecided` tells which line of defence fired: `false` — the cheap
 * pre-flight lookup found the key; `true` — the log's uniqueness constraint
 * decided a race two concurrent requests were running.
 *
 * @api
 */
final readonly class TransitionReplayed
{
    public function __construct(
        public string $workflow,
        public string $subjectId,
        public string $transition,
        public string $idempotencyKey,
        public bool $storageDecided,
    ) {}
}
