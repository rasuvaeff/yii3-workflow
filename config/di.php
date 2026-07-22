<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Yiisoft\Definitions\Reference;

/** @var array $params */

$config = $params['rasuvaeff/yii3-workflow'] ?? [];
$config = is_array($config) ? $config : [];

$workflows = $config['workflows'] ?? [];
$workflows = is_array($workflows) ? $workflows : [];

return [
    IdempotencyContext::class => IdempotencyContext::class,

    WorkflowFactory::class => [
        '__construct()' => [
            'clock' => Reference::to(ClockInterface::class),
            // The application's PSR-14 dispatcher: guards and reactions are
            // ordinary listeners on the workflow event classes.
            'dispatcher' => Reference::optional(EventDispatcherInterface::class),
            // Intentionally optional and NOT bound here: the audit backend is
            // swappable, so exactly one source (a backend package or the
            // application) may bind TransitionLog.
            'log' => Reference::optional(TransitionLog::class),
            'idempotency' => Reference::to(IdempotencyContext::class),
        ],
    ],

    WorkflowRegistry::class => static fn (WorkflowFactory $factory): WorkflowRegistry => new WorkflowRegistry(
        definitions: $workflows,
        factory: $factory,
    ),
];
