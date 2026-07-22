<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

/**
 * Persistence seam for the transition history.
 *
 * This package deliberately does NOT bind an implementation: pick one in the
 * application (or install a backend package) exactly like `yiisoft/cache`
 * leaves `Psr\SimpleCache\CacheInterface` to a backend. Without a binding the
 * workflows still run — they just do not record history.
 *
 * @api
 */
interface TransitionLog
{
    public function append(TransitionRecord $record): void;

    /** @return list<TransitionRecord> Chronological history of one subject. */
    public function forSubject(string $workflow, string $subjectId): array;

    /** Whether this subject already had a transition applied with that key. */
    public function hasIdempotencyKey(string $workflow, string $subjectId, string $key): bool;
}
