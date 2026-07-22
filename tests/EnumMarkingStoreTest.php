<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\Yii3Workflow\EnumMarkingStore;
use Rasuvaeff\Yii3Workflow\Tests\Support\Order;
use Rasuvaeff\Yii3Workflow\Tests\Support\OrderStatus;
use Symfony\Component\Workflow\Marking;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(EnumMarkingStore::class)]
final class EnumMarkingStoreTest
{
    public function readsTheEnumPropertyAsAMarking(): void
    {
        $marking = $this->store()->getMarking(new Order());

        Assert::same(\array_keys($marking->getPlaces()), ['pending']);
    }

    public function writesAPlaceBackAsAnEnumCase(): void
    {
        $order = new Order();

        $this->store()->setMarking($order, new Marking(['paid' => 1]));

        Assert::same($order->status(), OrderStatus::Paid);
    }

    public function reachesAPrivatePropertyOfAParentClass(): void
    {
        $order = new class extends Order {};

        $this->store()->setMarking($order, new Marking(['shipped' => 1]));

        Assert::same($order->status(), OrderStatus::Shipped);
    }

    public function rejectsANonEnumClass(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('is not a backed enum');

        new EnumMarkingStore(\stdClass::class);
    }

    public function rejectsASubjectWithoutTheProperty(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('has no property "status"');

        $this->store()->getMarking(new \stdClass());
    }

    public function rejectsAPropertyHoldingSomethingElse(): void
    {
        $subject = new class {
            private string $status = 'pending';

            public function keep(): string
            {
                return $this->status;
            }
        };

        Expect::exception(\LogicException::class)->withMessageContaining('must hold a backed enum, string given');

        $this->store()->getMarking($subject);
    }

    public function rejectsAnUninitializedProperty(): void
    {
        $subject = new readonly class {
            private OrderStatus $status;

            public function status(): OrderStatus
            {
                return $this->status;
            }
        };

        Expect::exception(\LogicException::class)->withMessageContaining('is not initialized');

        $this->store()->getMarking($subject);
    }

    public function rejectsAMultiPlaceMarking(): void
    {
        Expect::exception(\LogicException::class)->withMessageContaining('single-state markings only');

        $this->store()->setMarking(new Order(), new Marking(['pending' => 1, 'paid' => 1]));
    }

    private function store(): EnumMarkingStore
    {
        return new EnumMarkingStore(OrderStatus::class);
    }
}
