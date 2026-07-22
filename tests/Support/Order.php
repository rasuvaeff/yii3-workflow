<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

use Rasuvaeff\Yii3Workflow\SubjectIdentity;

/** Aggregate with a private enum status and no setter — the realistic shape. */
class Order implements SubjectIdentity
{
    private OrderStatus $status = OrderStatus::Pending;

    public function __construct(
        private readonly string $id = 'o-1',
        private bool $paid = false,
    ) {}

    #[\Override]
    public function workflowSubjectId(): string
    {
        return $this->id;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function markPaid(): void
    {
        $this->paid = true;
    }
}
