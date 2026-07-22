# Examples

Runnable scripts showing typical `rasuvaeff/yii3-workflow` usage. Each script is
self-contained: `php examples/<name>.php` prints a trace and exits cleanly.

| Script | Shows | External dependencies |
|---|---|---|
| `order-workflow.php` | The whole loop without a framework: a workflow built from the `params.php` array shape, a guard as a plain PSR-14 listener, an idempotency key skipping a replay, and the audit trail | None |

## Running

```bash
php examples/order-workflow.php
```

When running from a package checkout, the script uses that checkout's
`vendor/autoload.php`. Inside a Yii3 application the registry comes from the
container instead: `$registry = $container->get(WorkflowRegistry::class);`.
