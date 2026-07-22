# AGENTS.md — yii3-workflow

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-workflow` (namespace `Rasuvaeff\Yii3Workflow`) is glue, not an
engine: the state machine is `symfony/workflow`. The package supplies what the
standalone component lacks in a Yii3 application — a registry of workflows by
name built from `params.php`, a marking store for private backed-enum status
properties, a bridge from Symfony's name-based event dispatch to the
application's PSR-14 dispatcher, a transition history, replay protection, and a
`workflow:dump` command.

Public API: `WorkflowRegistry`, `WorkflowFactory`, `IdempotentWorkflow`,
`EnumMarkingStore`, `WorkflowEventDispatcher`, `SubjectIdentity`,
`Audit\{TransitionLog, TransitionRecord, InMemoryTransitionLog, AuditListener,
IdempotencyContext, DuplicateIdempotencyKey}`, `Command\WorkflowDumpCommand`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. The single scoped
   `MixedAssignment` handler in `psalm.xml` covers one untyped third-party
   boundary and is documented there — do not widen it.
3. **Do not reimplement symfony/workflow.** If a feature exists upstream, wire
   it; the value of this package is the integration, and every line of engine
   logic added here is a line someone must own forever.
4. **Preserve the public contract.** Update README.md + README.ru.md + llms.txt
   + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **The dispatcher contract is Symfony's, the implementation is the app's.**
  `Workflow` type-hints `Symfony\Contracts\EventDispatcher\EventDispatcherInterface`
  (name-aware), so a PSR-14 dispatcher cannot be passed directly. That is why
  `WorkflowEventDispatcher` exists. The package requires
  `symfony/event-dispatcher-contracts`, never `symfony/event-dispatcher`.
- **Forward each event once.** Workflow dispatches every event three times
  (`workflow.guard`, `workflow.duel.guard`, `workflow.duel.guard.activate`).
  `WorkflowEventDispatcher` forwards only the generic name; adding the others
  would call class-based PSR-14 listeners three times per check.
- **`IdempotentWorkflow` must NOT implement `WorkflowInterface`.** That
  interface gained `getEnabledTransition()` in the 7.x line; implementing it
  breaks the `^6.4 || ^7.0 || ^8.0` range. It is a decorator with `workflow()`
  as the escape hatch.
- **`TransitionLog` is a swappable interface — the package must not bind it**
  in `config/di.php` (`yiisoft/config` rejects two vendor packages defining one
  key in a group). It is injected with `Reference::optional()`; everything works
  without it, minus history and replay detection.
- **State machine transitions have exactly one source.** `from: [a, b]` is
  expanded into one `Transition` per source before validation; without that
  expansion Symfony silently never enables the transition. Symfony's
  `StateMachineValidator`/`WorkflowValidator` runs on every build — keep it.
- `EnumMarkingStore` walks up the class hierarchy to find the property and
  rejects uninitialized or non-enum values loudly; it refuses multi-place
  markings, because an enum property cannot hold two states.
- The audit trail exists only because `symfony/workflow` stores the current
  marking and nothing else. `AuditListener` skips subjects without
  `SubjectIdentity` rather than inventing an id.
- Idempotency has TWO layers: the pre-flight `hasIdempotencyKey()` lookup and
  `append()` throwing `DuplicateIdempotencyKey` when a backend's unique
  constraint fires; `applyOnce()` turns the latter into `false`. Never
  "simplify" that catch away — the lookup alone is check-then-act and loses
  races. A key without a bound log is a `LogicException`, not a silent pass.
  The subject is already mutated when the constraint rejects the write, so the
  documented recipe (wrap in a transaction) is part of the contract.
- `IdempotencyContext` is request-scoped mutable state, deliberately: workflow
  events do not carry `apply()`'s `$context` in a form a listener can rely on
  across Symfony versions. In a long-running worker, keep one instance per job.
- `config/di.php` is covered by neither psalm (src-only), nor cs, nor the type
  checker — `tests/ConfigWiringTest.php` exercises it inside the build gate.
  Keep that test in sync with the definitions.
- Benchmarks separate the cold path (building a workflow: parsing + validation)
  from the hot path (`apply`/`can` on a prebuilt one) and share the workflow via
  a static. Merging them would hide the hot path behind construction cost.
- Test doubles come from `yiisoft/test-support`: `StaticClock` (wrapped by
  `tests/Support/Clocks`) and `SimpleEventDispatcher`. Do not hand-roll PSR
  doubles here — `SimpleEventDispatcher::isInstanceOfTriggered($class, $times)`
  is also what proves the bridge delivers one event per check.
- `rector.php` skips `FlipTypeControlToUseExclusiveTypeRector`: it rewrites
  `=== null` into `!$x instanceof Vendor\Fqcn`. Keep null checks as `=== null`.
- `infection.json5` gates at MSI 100 with a few documented per-method `ignore`
  entries for provably equivalent mutants (enum casts, marking count,
  `array_values()` on a sequential array). Add an ignore only with a written
  argument; never to cover a missing test.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
