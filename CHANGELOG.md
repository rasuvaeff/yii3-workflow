# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-yii3-workflow/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.

## 1.0.0 — 2026-07-24

- Initial package: Yii3 integration for `symfony/workflow`. `WorkflowRegistry`
  builds workflows lazily from the `params.php` array shape (backed enums or
  strings, `state_machine` or Petri-net `workflow`), validated with Symfony's own
  definition validators, with multi-source state machine transitions expanded
  into one transition per source.
- `WorkflowEventDispatcher` bridges Symfony's name-based dispatch to the
  application's PSR-14 dispatcher and delivers each event exactly once, so
  guards and reactions are ordinary Yii3 listeners and the package depends on
  the event-dispatcher contracts rather than an implementation.
- `EnumMarkingStore` reads and writes a backed-enum status property of any
  visibility, so aggregates need no public setter.
- Transition history through the `TransitionLog` seam (`TransitionRecord`,
  `AuditListener`, `InMemoryTransitionLog`); the seam is intentionally left
  unbound so exactly one source binds a backend.
- `IdempotentWorkflow::applyOnce()` skips a transition whose idempotency key is
  already recorded for the subject.
- `workflow:dump` console command rendering Mermaid, PlantUML or Graphviz.
- Make replay protection enforceable: `TransitionLog::append()` must throw
  `DuplicateIdempotencyKey` when the (workflow, subject, key) triple already
  exists, `IdempotentWorkflow::applyOnce()` reports that as a replay, and
  supplying a key without a bound log raises a `LogicException` instead of
  silently doing nothing.
- Make broken idempotency wiring loud: `applyOnce()` now also refuses a key
  when no `IdempotencyContext` is bound, and verifies after the transition that
  the key was actually recorded — a workflow without a matching `AuditListener`
  raises a `LogicException` instead of silently applying without protection.
- `EnumMarkingStore` rejects values of a different enum class than the
  configured one, even when the backing value collides with a place name.
- `workflow:dump` picks the diagram flavour from the workflow type: Petri-net
  `workflow` definitions are rendered with explicit transition nodes in every
  format; the separate `workflow-dot` format is gone.
- `TransitionGuard` base class for guard listeners: the workflow/transition
  filtering every bare PSR-14 guard had to repeat is done by the base,
  subclasses implement only the check.
- Every skipped replay is dispatched to the application's PSR-14 dispatcher as
  `Audit\TransitionReplayed`, with a flag telling whether the pre-flight lookup
  or the storage constraint decided.
- Declarative metadata: `metadata` (workflow-level), `placesMetadata` (keyed by
  place name, unknown places rejected) and a per-transition `metadata` key land
  in the definition's metadata store; state-machine expansion attaches the
  transition metadata to every expanded copy.
