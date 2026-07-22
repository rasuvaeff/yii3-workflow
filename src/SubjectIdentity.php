<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

/**
 * How the audit trail and the idempotency check identify a subject.
 *
 * `symfony/workflow` never asks who the subject is — it only moves a marking —
 * so an aggregate that wants a history or replay protection has to say it.
 *
 * @api
 */
interface SubjectIdentity
{
    public function workflowSubjectId(): string;
}
