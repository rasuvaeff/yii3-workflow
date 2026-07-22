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
    /**
     * Record a committed transition.
     *
     * An implementation MUST reject a record whose idempotency key is already
     * stored for the same workflow and subject, by throwing
     * {@see DuplicateIdempotencyKey}. A storage that can enforce this with a
     * unique constraint should do so: the pre-flight
     * {@see hasIdempotencyKey()} check is racy on its own, and only the write
     * can be atomic.
     *
     * @throws DuplicateIdempotencyKey
     */
    public function append(TransitionRecord $record): void;

    /** @return list<TransitionRecord> Chronological history of one subject. */
    public function forSubject(string $workflow, string $subjectId): array;

    /**
     * Whether this subject already had a transition applied with that key.
     *
     * A cheap pre-flight check that avoids the work of a transition that would
     * be rejected anyway; it is NOT the guarantee — {@see append()} is.
     */
    public function hasIdempotencyKey(string $workflow, string $subjectId, string $key): bool;
}
