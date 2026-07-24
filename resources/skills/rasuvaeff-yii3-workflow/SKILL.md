---
name: rasuvaeff-yii3-workflow
description: >-
  Yii3 wiring for symfony/workflow with transition history and idempotent
  transitions — WorkflowRegistry, IdempotentWorkflow, EnumMarkingStore,
  TransitionLog, AuditListener, TransitionGuard, workflow:dump. Use when
  writing, reviewing or debugging workflow/state-machine transitions, guards,
  audit trails or idempotency keys in a project that has this package
  installed, or when applyOnce() throws a LogicException or returns false.
---

# rasuvaeff/yii3-workflow

Glue over `symfony/workflow` for Yii3: a registry of workflows built from
`params.php`, an enum marking store, a PSR-14 event bridge, a transition
history and replay protection. Namespace `Rasuvaeff\Yii3Workflow\`.

## Safety rules — verify these on every change

1. **`TransitionLog` is intentionally NOT bound by this package.** Exactly one
   source binds it: a backend package (`rasuvaeff/yii3-workflow-db`) or the
   application. Binding it in two vendor packages fails at runtime with
   yiisoft/config `Duplicate key`. Without any binding workflows run but record
   nothing.

2. **An idempotency key with broken wiring throws, never silently skips.**
   `applyOnce($subject, $t, idempotencyKey: $k)` raises a `LogicException`
   when no `TransitionLog` or `IdempotencyContext` is bound, or when no
   `AuditListener` actually recorded the key. Do not "fix" that by catching
   it — fix the wiring (build workflows via `WorkflowFactory`).

3. **A key is scoped to (workflow, subject), not to a transition.** The same
   key on the same subject with a *different* transition returns `false`
   (treated as a replay). Generate a unique key per operation.

4. **The subject is already mutated in memory when the log write is rejected.**
   `TransitionLog::append()` throws `DuplicateIdempotencyKey` on a race;
   `applyOnce()` turns it into `false` — so wrap `applyOnce()` + save in ONE
   transaction (`WorkflowTransaction` from yii3-workflow-db), or a lost race
   persists a transition that officially "did not happen".

5. **In a `state_machine`, `from: [a, b]` is one transition per source.** The
   registry expands multi-source transitions before Symfony validation;
   a transition with two *targets* is a configuration error.

6. **`WorkflowRegistry::get()` returns an `IdempotentWorkflow` decorator**, not
   Symfony's `WorkflowInterface` (that interface differs across 6.4–8.x). Use
   `->workflow()` for the raw one. Guards/reactions are plain PSR-14 listeners
   on the event classes; each event arrives exactly once — narrow inside the
   listener with `getWorkflowName()` / `getTransition()`.

## Canonical usage

```php
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;

$workflow = $registry->get('order');

if (!$workflow->applyOnce($order, 'ship', idempotencyKey: $requestId)) {
    return;   // replay of the same request
}

foreach ($workflow->blockers($order, 'cancel') as $blocker) {
    echo $blocker->getMessage(), ' ', $blocker->getCode();
}
```

Definitions live in `params.php` under `'rasuvaeff/yii3-workflow' =>
['workflows' => [...]]` — places and endpoints accept backed enums or strings.

## Full API

The complete reference — the `params.php` definition shape, every
`IdempotentWorkflow` method, the `Audit\*` classes and the `workflow:dump`
formats — ships with the package: read `vendor/rasuvaeff/yii3-workflow/llms.txt`
before guessing a method or config key.
