<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Audit;

use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(IdempotencyContext::class)]
final class IdempotencyContextTest
{
    public function hasNoKeyByDefault(): void
    {
        Assert::null((new IdempotencyContext())->current());
    }

    public function exposesTheKeyForTheDurationOfTheOperation(): void
    {
        $context = new IdempotencyContext();

        $seen = $context->during('req-1', static fn(): ?string => $context->current());

        Assert::same($seen, 'req-1');
        Assert::null($context->current());
    }

    public function restoresTheOuterKeyAfterNesting(): void
    {
        $context = new IdempotencyContext();

        $inner = $context->during('outer', static fn(): ?string => $context->during(
            'inner',
            static fn(): ?string => $context->current(),
        ));

        Assert::same($inner, 'inner');
        Assert::null($context->current());
    }

    public function clearsTheKeyWhenTheOperationThrows(): void
    {
        $context = new IdempotencyContext();

        try {
            $context->during('req-1', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            Assert::null($context->current());

            return;
        }

        Expect::exception(\RuntimeException::class);
    }
}
