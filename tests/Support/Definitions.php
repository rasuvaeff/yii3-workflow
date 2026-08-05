<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

/** The `params.php` shape used across the tests. */
final readonly class Definitions
{
    /** @return array<string, mixed> */
    public static function order(): array
    {
        return [
            'type' => 'state_machine',
            'initial' => OrderStatus::Pending,
            'places' => OrderStatus::cases(),
            'markingStore' => ['type' => 'enum', 'enum' => OrderStatus::class, 'property' => 'status'],
            'transitions' => [
                ['name' => 'pay', 'from' => OrderStatus::Pending, 'to' => OrderStatus::Paid],
                ['name' => 'ship', 'from' => OrderStatus::Paid, 'to' => OrderStatus::Shipped],
                ['name' => 'cancel', 'from' => [OrderStatus::Pending, OrderStatus::Paid], 'to' => OrderStatus::Cancelled],
            ],
        ];
    }

    /** A Petri-net workflow: `publish` needs both reviews at once. @return array<string, mixed> */
    public static function document(): array
    {
        return [
            'type' => 'workflow',
            'initial' => 'draft',
            'places' => ['draft', 'review_legal', 'review_style', 'published'],
            'markingStore' => ['type' => 'method', 'property' => 'places'],
            'transitions' => [
                ['name' => 'submit', 'from' => 'draft', 'to' => ['review_legal', 'review_style']],
                ['name' => 'publish', 'from' => ['review_legal', 'review_style'], 'to' => 'published'],
            ],
        ];
    }

    /** A cyclic state machine (Doing <-> Blocked) for stateful/model-based tests. @return array<string, mixed> */
    public static function task(): array
    {
        return [
            'type' => 'state_machine',
            'initial' => TaskStatus::Todo,
            'places' => TaskStatus::cases(),
            'markingStore' => ['type' => 'enum', 'enum' => TaskStatus::class, 'property' => 'status'],
            'transitions' => [
                ['name' => 'start', 'from' => TaskStatus::Todo, 'to' => TaskStatus::Doing],
                ['name' => 'block', 'from' => TaskStatus::Doing, 'to' => TaskStatus::Blocked],
                ['name' => 'unblock', 'from' => TaskStatus::Blocked, 'to' => TaskStatus::Doing],
                ['name' => 'finish', 'from' => TaskStatus::Doing, 'to' => TaskStatus::Done],
            ],
        ];
    }
}
