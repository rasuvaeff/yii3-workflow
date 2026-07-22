<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Audit;

/**
 * Carries the current idempotency key from the caller down to
 * {@see AuditSubscriber}.
 *
 * Workflow events do not forward the `$context` array of `apply()` in a form a
 * subscriber can rely on across Symfony versions, so the key travels in this
 * request-scoped holder instead. PHP-FPM runs one request per process, so the
 * scope is the request; in a long-running worker keep one instance per job.
 *
 * @api
 */
final class IdempotencyContext
{
    private ?string $key = null;

    public function current(): ?string
    {
        return $this->key;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function during(?string $key, callable $operation): mixed
    {
        $previous = $this->key;
        $this->key = $key;

        try {
            return $operation();
        } finally {
            $this->key = $previous;
        }
    }
}
