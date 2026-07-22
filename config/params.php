<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Workflow\Command\WorkflowDumpCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'workflow:dump' => WorkflowDumpCommand::class,
        ],
    ],
    'rasuvaeff/yii3-workflow' => [
        // 'duel' => [
        //     'type' => 'state_machine',
        //     'initial' => DuelStatus::Pending,
        //     'places' => DuelStatus::cases(),
        //     'markingStore' => ['type' => 'enum', 'enum' => DuelStatus::class, 'property' => 'status'],
        //     'transitions' => [
        //         ['name' => 'activate', 'from' => DuelStatus::Pending, 'to' => DuelStatus::Active],
        //     ],
        // ],
        'workflows' => [],
    ],
];
