# rasuvaeff/yii3-workflow

![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-workflow?label=stable)
![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-workflow?label=downloads)
![Build](https://github.com/rasuvaeff/yii3-workflow/actions/workflows/build.yml/badge.svg)
![Static analysis](https://github.com/rasuvaeff/yii3-workflow/actions/workflows/static-analysis.yml/badge.svg)
![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-workflow?label=license)

Интеграция [`symfony/workflow`](https://symfony.com/doc/current/workflow.html) в
Yii3: обвязка, которую в Symfony даёт FrameworkBundle, плюс то, чего компонент
сознательно не делает — история переходов и защита от повторов.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный API-справочник,
> созданный для LLM.

[English version](README.md)

## Что делает

- **Автоматы по имени.** Описания лежат в `params.php`; `WorkflowRegistry`
  собирает их лениво и отдаёт из контейнера.
- **Enum marking store.** Читает и пишет статус-свойство с backed enum любой
  видимости — агрегату не нужен публичный сеттер ради библиотеки.
- **PSR-14, а не диспетчер Symfony.** Guard'ы и реакции — обычные слушатели на
  классах событий workflow, доставляемые через собственный PSR-14 диспетчер
  приложения (`yiisoft/event-dispatcher`). Пакет зависит от *контрактов*
  event-dispatcher, а не от реализации.
- **История переходов.** `symfony/workflow` хранит только текущий marking; пакет
  записывает по строке на каждый закоммиченный переход через шов `TransitionLog`,
  который вы связываете со своим хранилищем.
- **Идемпотентность.** `applyOnce($subject, $transition, $key)` пропускает
  переход, чей ключ уже есть в логе — ответ на двойной сабмит формы.
- **Ранняя валидация.** Описание проверяется валидаторами самого Symfony в момент
  сборки автомата, поэтому переход, который никогда не сработал бы, — это ошибка,
  а не молчаливый no-op.
- **`workflow:dump`.** Консольная команда рисует любой настроенный автомат в
  Mermaid, PlantUML или Graphviz.

## Требования

- PHP 8.3 / 8.4 / 8.5
- `symfony/workflow` ^6.4 / ^7.0 / ^8.0
- `psr/clock`, `psr/event-dispatcher`, `symfony/event-dispatcher-contracts`
- `symfony/console` (для команды дампа), `yiisoft/definitions`

## Установка

```bash
composer require rasuvaeff/yii3-workflow
```

`yiisoft/config` подхватит пакет автоматически. По умолчанию ничего лишнего не
биндится — см. [Историю переходов](#история-переходов) про единственный биндинг,
который может понадобиться.

## Конфигурация

```php
// config/common/params.php
use App\Domain\Order\OrderStatus;

return [
    'rasuvaeff/yii3-workflow' => [
        'workflows' => [
            'order' => [
                'type' => 'state_machine',            // или 'workflow' для сети Петри
                'initial' => OrderStatus::Pending,
                'places' => OrderStatus::cases(),
                'markingStore' => [
                    'type' => 'enum',                 // или 'method' для getX()/setX()
                    'enum' => OrderStatus::class,
                    'property' => 'status',
                ],
                'transitions' => [
                    ['name' => 'pay', 'from' => OrderStatus::Pending, 'to' => OrderStatus::Paid],
                    ['name' => 'ship', 'from' => OrderStatus::Paid, 'to' => OrderStatus::Shipped],
                    ['name' => 'cancel', 'from' => [OrderStatus::Pending, OrderStatus::Paid], 'to' => OrderStatus::Cancelled],
                ],
            ],
        ],
    ],
];
```

Места и концы переходов принимают backed enum или строки. В state machine переход
с несколькими источниками разворачивается в отдельный переход на каждый источник —
ровно так же нормализует YAML-конфигурацию Symfony.

## Использование

```php
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;

final readonly class ShipOrderHandler
{
    public function __construct(private WorkflowRegistry $registry) {}

    public function handle(Order $order, string $requestId): void
    {
        $workflow = $this->registry->get('order');

        if (!$workflow->applyOnce($order, 'ship', idempotencyKey: $requestId)) {
            return;   // для этого запроса переход уже применён
        }

        $this->orders->save($order);
    }
}
```

| Метод | Возвращает | Заметка |
|---|---|---|
| `applyOnce($subject, $transition, $key = null, $context = [])` | `bool` | `false` = повтор пропущен |
| `apply($subject, $transition, $context = [])` | `Marking` | Без проверки идемпотентности |
| `can($subject, $transition)` | `bool` | Guard'ы вычисляются |
| `blockers($subject, $transition)` | `TransitionBlockerList` | Почему недоступно |
| `enabledTransitions($subject)` | `iterable<Transition>` | Сейчас возможные переходы |
| `name()` / `definition()` / `workflow()` | — | Имя, граф, обёрнутый `WorkflowInterface` |

### Guard'ы и реакции

Каждое событие workflow доходит до PSR-14 диспетчера приложения ровно один раз —
под общим именем, а не по разу на каждый вариант `workflow.<name>.<event>`, —
поэтому слушатель по классу события не вызывается трижды:

```php
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

final class ShipOnlyWhenPaid
{
    public function __invoke(GuardEvent $event): void
    {
        if ($event->getWorkflowName() !== 'order' || $event->getTransition()?->getName() !== 'ship') {
            return;
        }

        if (!$event->getSubject()->isPaid()) {
            $event->addTransitionBlocker(new TransitionBlocker('Payment is not captured', 'order.unpaid'));
        }
    }
}
```

Регистрируется как любой другой слушатель Yii3 (`config/common/events-web.php`).
Сообщение и код блокировки возвращаются через `blockers()`, поэтому API может
объяснить пользователю, почему действие недоступно.

### История переходов

`TransitionLog` сознательно **не** биндится этим пакетом — ровно как
`yiisoft/cache` оставляет `Psr\SimpleCache\CacheInterface` бэкенду. Без биндинга
автоматы работают, просто ничего не записывают.

```php
// config/common/di/workflow.php
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;

return [
    TransitionLog::class => App\Infrastructure\DbTransitionLog::class,
];
```

Реализация сохраняет строки `TransitionRecord::toArray()` и отвечает на
`hasIdempotencyKey()`. Для тестов и разовых скриптов есть
`InMemoryTransitionLog`.

### Дамп диаграммы

`WorkflowDumpCommand` регистрируется как `workflow:dump` через `params.php`:

```bash
./yii workflow:dump                     # список настроенных автоматов
./yii workflow:dump order               # Mermaid (по умолчанию)
./yii workflow:dump order --format=puml # PlantUML
./yii workflow:dump order --format=dot  # Graphviz
```

## Безопасность

Пакет не выполняет собственного I/O: он двигает marking на переданном объекте и
отдаёт строки аудита в ваш `TransitionLog`. Имена переходов и мест приходят из
конфигурации, а не от пользователя — так и должно остаться, потому что имя,
дошедшее до `apply()`, выбирает поведение. Проверки прав — в guard-слушателях:
переход без guard'а разрешён каждому, кто дотянулся до обработчика.

## Примеры

См. [`examples/`](examples/) — исполняемый скрипт со всем циклом.

## Разработка

```bash
make install
make build       # validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make mutation    # нужен pcov; Makefile его ставит
```

PHP и Composer на хосте нет — все цели выполняются в Docker-образе `composer:2`.

## Лицензия

BSD-3-Clause. См. [`LICENSE.md`](LICENSE.md).
