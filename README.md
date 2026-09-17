# F4-Formula

F4-Formula это прикладной PHP-фреймворк, выросший из идей Fat-Free Framework, но сильно расширенный под реальные проектные задачи: модульную архитектуру, DI, безопасный HTTP-слой, компонентный UI, сервисный слой для данных, миграции, кэширование и удобную схему обновления ядра без поломки боевого кода.

## Зачем нужен F4-Formula

Обычный микрофреймворк хорош на старте, но быстро начинает упираться в одни и те же проблемы:

- бизнес-код смешивается с ядром
- сложно обновлять базу проекта без конфликтов
- роутинг и middleware начинают обрастать хаотично
- работа с БД превращается в набор разрозненных SQL-хелперов
- модульные фичи тяжело переносить между проектами
- кеш, шаблоны, компоненты и миграции живут каждая своей жизнью

F4-Formula решает это через понятное разделение слоёв, предсказуемую структуру и набор уже встроенных инструментов.

---

## Ключевые преимущества

### 1. Чёткое разделение ядра и прикладного кода

Фреймворк изначально делит проект на два слоя:

- `/lib/` — ядро, общая платформа, базовые сервисы, HTTP, DI, View, Events, Utils
- `/local/` — код конкретного проекта, его контроллеры, компоненты, маршруты, зависимости и расширения

Такой подход даёт главное преимущество: можно развивать и обновлять ядро отдельно, не превращая проект в свалку правок по всей кодовой базе.

### 2. Переопределение без хака ядра

Конфиги и bootstrap-файлы подгружаются в фиксированном порядке через `lib/data` и потом `local/data`, поэтому проектный слой может расширять или переопределять поведение ядра без переписывания базовых файлов.

Это очень удобно для:

- маршрутов
- middleware
- helpers
- DI definitions через `services.yaml` и `.definitions.php`
- планировщика
- констант и служебной конфигурации

### 3. Современный HTTP-слой поверх классического PHP

Вместо прямой завязки на `$_SERVER`, `$_POST`, `php://input` и ручную сборку ответа, во фреймворке есть отдельный HTTP-слой:

- `Environment` является единым источником нормализованного HTTP-окружения
- `Request` и `Response` работают как отдельные объекты
- `Router` управляет request pipeline
- `ErrorHandler` централизованно переводит ошибки в HTML/JSON `Response`
- поддерживаются trusted proxies / trusted hosts
- JSON body читается один раз
- есть CLI-режим
- поддерживается middleware pipeline

### 4. Встроенная компонентная система

Во фреймворке есть собственные компоненты с менеджером регистрации и запуска:

- `BaseComponent`
- `ComponentManager`
- шаблоны компонентов
- `blueprint.yaml` для дефолтных параметров
- `result_modifier.php`
- `component_epilog.php`
- автоматическое подключение CSS/JS
- кэширование вывода компонента


### 5. Сервисный слой для данных вместо хаотичных SQL-хелперов

`DataManager` даёт единый способ работы с таблицами:

- `getList()`
- `getById()`
- `count()`
- `getRaw()`
- `add()`
- `update()`
- `delete()`
- `tx()` для транзакций

Плюс к этому:

- карта полей через `getFieldsMap()`
- валидация входных данных
- авто-гидрация
- DTO по желанию
- защита от небезопасных SQL-конструкций
- централизованный реестр менеджеров

В итоге проект получает нормальный data layer, а не разрозненный набор запросов.

### 6. Модульная архитектура для реальных фич

F4-Formula поддерживает автономные модули с собственной структурой:

- `setting.yaml`
- `include.php`
- `data/`
- `db/migrations`
- `db/seeds`
- `install/index.php`
- `install/ui`
- `lib/Component` и другие классы модуля

Модули можно подключать, отключать, устанавливать и переносить между проектами.

### 7. Нормальная история с миграциями

Для модулей используется связка:

- `ModuleMigration`
- `PhinxMigrator`
- глобальный `lib/phinx.php`
- вызов миграций по slug модуля
- поддержка сидов
- rollback
- снапшоты перед откатом

### 8. Гибкий кэш из коробки

Во фреймворке есть:

- общий кэш
- кэш компонентов
- кэш шаблонов
- data-layer cache с тегами
- защита от cache stampede через lock
- несколько адаптеров:
  - File
  - APCu
  - Memcached
  - Redis

### 9. Безопасность не в виде “когда-нибудь потом”

Во фреймворк уже заложены базовые защитные механики:

- CSRF middleware
- security headers
- cache headers middleware
- нормализация и санитизация заголовков
- trusted proxy / host логика
- read-only контроль для raw SQL
- безопасные cookie/session параметры
- защищённая обработка JSON body

### 10. Удобная инфраструктура для продакшена

Из коробки есть полезные прикладные инструменты:

- единый composition root через `Kernel`
- DI через PHP-DI
- YAML-конфиги
- планировщик задач
- assets manager
- markdown renderer
- логирование
- ротация логов
- модульный bootstrap
- helper-функции для быстрого доступа к app/template/assets/components

---

## Что именно улучшено по сравнению с базовым F3-подходом

Если коротко, F4-Formula это практическая эволюция под крупные прикладные проекты.

### Было типично в классическом микрофреймворке

- минимум структуры
- много прямой работы с superglobals
- разрозненные сервисы
- слабая модульность
- сложнее масштабировать проект без договорённостей

### Стало в F4-Formula

- разделение платформы и проекта
- единый DI-контур
- объектный HTTP-слой
- предсказуемый bootstrap
- модульная архитектура
- компонентный UI-слой
- сервисный слой для БД
- встроенные миграции модулей
- расширяемый кэш
- безопаснее работа с запросами и raw SQL
- удобный путь для роста от маленького проекта к большому

---

## Архитектура

```text
/lib
  /app
    /Base
    /Http
    /Controller
    /Service
    /View
    /Utils
    /Events
    /Component
    /Modules
    /Migrations
  /data
    constants.php
    services.yaml
    .definitions.php
    helpers.php
    middleware.php
    routes.php
    schedule.php
  phinx.php
  prolog.php

/local
  /app
    /Controller
    /Service
    /Component
  /data
    constants.php
    services.yaml
    .definitions.php
    helpers.php
    middleware.php
    routes.php
    schedule.php
  /modules
    /Blog
      setting.yaml
      include.php
      /data
      /db
        /migrations
        /seeds
      /install
      /lib
---

## Текущее ядро Stage 2

Stage 2 переводит F4-Formula от исторического «центрального объекта, который умеет всё» к разделённому ядру с явными владельцами состояния и инфраструктуры.

Главное правило текущей архитектуры:

> `Kernel` собирает приложение, DI передаёт зависимости, `F4` остаётся удобным facade, но не является Service Locator и не должен самостоятельно реализовывать работу подсистем.

### Kernel — единственная точка bootstrap

`App\Base\Kernel` отвечает за composition root:

1. создаёт базовые runtime-объекты ядра;
2. инициализирует `Environment`;
3. создаёт ранний `ErrorHandler`;
4. загружает основной `config.yaml`;
5. выполняет discovery модулей;
6. собирает DI definitions;
7. строит PHP-DI container;
8. запускает installers/migrations после готовности DI;
9. загружает runtime-файлы `helpers.php`, `middleware.php`, `routes.php`;
10. отдельно загружает `schedule.php` только в cron/CLI schedule-фазе.

Обычный прикладной класс не должен обращаться к `Kernel::instance()->get()` вместо constructor injection.

Допустимые места прямого обращения к Kernel:

- `prolog.php` и другие composition-root entry points;
- bootstrap/data-файлы;
- cron/schedule entry point;
- динамическая инфраструктура, где класс определяется только во время выполнения.

### F4 больше не Service Locator

Устаревшие API предыдущей архитектуры удаляются:

```php
F4::instance();
$f4->getDI(...);
$f4->hasDI(...);
$f4->resolveFromContainer(...);
```

В обычных классах зависимости передаются через constructor injection:

```php
final class ExampleService
{
    public function __construct(
        private SomeDependency $dependency
    ) {}
}
```

Если инфраструктуре нужно создать runtime class-string, используется `ServiceLocator::make()`.

---

## F4Store: отдельное состояние framework

Исторический `$hive` больше не должен быть внутренним состоянием большого `F3Tools`.

Для этого введён `App\Base\F4Store`.

`F4Store` — простой объект хранения framework-state:

```text
F4Store
├── ref()
├── exists()
├── get()
├── set()
├── clear()
├── mset()
├── extend()
└── all()
```

Он не отвечает за:

- HTTP;
- DI;
- Router;
- Cookie;
- Session;
- Cache;
- bootstrap;
- логирование.

`F4` сохраняет удобный публичный API:

```php
$f4->get('DEBUG');
$f4->set('UI', '...');
$f4->exists('KEY');
```

но эти операции делегируются `F4Store`.

Это позволяет сохранить удобство старого API без смешивания хранения состояния с логикой ядра.

---

## F3Tools и F3Helpers

Большой исторический `F3Tools` разделяется по ответственности.

### F3Tools

`F3Tools` содержит обычные инструменты общего назначения:

- `parse()`
- `split()`
- `extract()`
- `stringify()`
- `csv()`
- `format()`
- `export()`
- `constants()`
- `hash()`
- `encode()` / `decode()`
- `recursive()`
- `scrub()`
- `serialize()` / `unserialize()`
- `trace()`
- `grab()`
- `call()`
- `chain()`
- `relay()`
- `mutex()`
- `read()` / `write()`
- `highlight()`

Эти методы рассматриваются как инструменты, а не как владельцы HTTP/DI/bootstrap.

### F3Helpers

`F3Helpers` содержит framework-facing convenience API и временную обвязку вокруг отдельных сервисов.

Главное правило:

> helper может проксировать вызов в сервис, но не должен дублировать реализацию этого сервиса.

Например, HTTP-данные берутся из `Environment/Request`, cache-операции выполняет Cache service, cookie-операции выполняет CookieService.

Cookie/session/cache пока сохраняют удобные facade-методы через `F4`, но конечным владельцем поведения остаётся соответствующий сервис.

Bootstrap/runtime будет вынесен из helper-слоя отдельным этапом.

---

## HTTP: Environment является источником истины

`Environment` отвечает за сбор и нормализацию HTTP-окружения:

- snapshot `$_SERVER`;
- headers;
- raw request body;
- method override;
- scheme;
- host;
- port;
- base path;
- trusted proxies;
- trusted hosts;
- client IP;
- построение `Request`.

Другие классы не должны повторно вычислять эти данные.

Например:

```php
$request = $environment->getRequest();

$request->clientIp();
$request->isAjax();
$request->getHeader('User-Agent');
```

Если `F4` предоставляет короткие convenience-методы для этих данных, они должны быть только proxy к `Environment/Request`.

---

## Централизованная обработка ошибок

В Stage 2 старый `$f4->error()` удаляется.

За конечную обработку ошибок отвечает один:

```php
App\Http\ErrorHandler
```

`ErrorHandler` зависит только от:

```php
App\Http\Environment
```

### Два режима одного ErrorHandler

До готовности DI обработчик доступен статически:

```php
ErrorHandler::bootstrap($environment)->register();
```

После сборки PHP-DI тот же экземпляр регистрируется в container и передаётся через constructor injection.

То есть static bootstrap mode и object DI mode используют одно состояние, а не два разных обработчика.

### Ошибки PHP

PHP warnings/errors переводятся в `ErrorException` и далее проходят общий Throwable pipeline.

Глобальный exception/shutdown handler используется как последняя аварийная сетка для ошибок, возникших вне Router pipeline:

- bootstrap;
- DI build;
- module bootstrap;
- cron/CLI;
- fatal shutdown error.

### Ошибки HTTP request pipeline

Основная HTTP boundary находится в `Router`.

```text
Middleware
    ↓
Controller
    ↓
Template
    ↓
Service
    ↓
Throwable
    ↓
Router
    ↓
ErrorHandler
    ↓
Response
```

`MiddlewareDispatcher`, контроллеры и шаблоны не должны создавать собственные независимые error-handling системы.

### HTML и JSON

`ErrorHandler` определяет вид ответа по текущему HTTP context.

Для JSON используется существующая структура `Response`:

```json
{
  "flag": "error",
  "data": [],
  "errors": []
}
```

Для аварийного HTML 500 используется минимальный renderer, который не зависит от обычного `Template`, компонентов, assets или DI. Это защищает систему от рекурсивного падения, если первичная ошибка произошла именно внутри View.

HTTP-состояния вроде `404` и `405` тоже проходят через `ErrorHandler`, но являются штатным HTTP result, а не обязательно системным исключением.

---

## Runtime factories

Для объектов, которые создаются динамически по class-string, действует отдельный runtime-factory контракт.

Основные семейства:

- `BaseComponent`
- `InstallerBase`
- `DataManager`

Такие классы реализуют:

```php
RuntimeFactoryInterface
```

Контракт разделяет два типа данных:

```text
constructor
    = обычные class-specific DI dependencies

setFactoryContext()
    = runtime/framework context от фабрики
```

Пример:

```php
$component = $services->make($className, [
    'f4'           => $f4,
    'assets'       => $assets,
    'cacheHelper'  => $cacheHelper,
    'templateName' => $template,
    'folder'       => $folder,
    'arParams'     => $params,
]);
```

Нельзя переносить runtime context обратно в constructor только для того, чтобы упростить создание объекта.

`ServiceLocator::make()`:

1. создаёт новый объект;
2. резолвит типизированные constructor dependencies из PHP-DI;
3. после создания передаёт runtime context через `setFactoryContext()`.

Обычные application services не должны использовать этот механизм вместо constructor injection.

---

## DI definitions

Штатные определения DI находятся в:

```text
lib/data/services.yaml
lib/data/.definitions.php

local/data/services.yaml
local/data/.definitions.php
```

Исторический `dependencies.php` больше не участвует в штатном bootstrap Stage 2.

При включённом autowiring простые классы лучше не описывать вручную без необходимости.

Например:

```php
Router::class => autowire(Router::class),
```

лучше длинной definition с перечислением каждого constructor parameter, если нет специальной причины фиксировать аргументы вручную.

Это снижает риск ситуации:

```text
в constructor добавили dependency
↓
старую explicit definition забыли обновить
↓
PHP-DI InvalidDefinition
```

Singleton/runtime-объекты composition root при необходимости регистрируются явно через `DI\value()`.

---

## Router и middleware

`Router` отвечает за жизненный цикл HTTP request:

```text
match route
   ↓
BEFORE middleware
   ↓
MAIN middleware chain
   ↓
handler / controller
   ↓
AFTER middleware
   ↓
Response
```

Handler-классы создаются через DI/infrastructure, а не через F4 Service Locator API.

Middleware может быть:

- глобальным;
- групповым;
- route-level;
- BEFORE;
- MAIN;
- AFTER.

Ошибка внутри любой части pipeline поднимается до Router boundary и передаётся `ErrorHandler`.

---

## Актуальный bootstrap data flow

Упрощённо текущая последовательность выглядит так:

```text
prolog.php
   ↓
Kernel::instance()
   ↓
Environment
   ↓
early ErrorHandler
   ↓
F4 + F4Store
   ↓
config.yaml
   ↓
module discovery
   ↓
services.yaml / .definitions.php
   ↓
PHP-DI build
   ↓
module installers / migrations
   ↓
helpers.php
middleware.php
routes.php
   ↓
Router::run()
```

Schedule запускается отдельной фазой:

```text
cron / CLI
   ↓
Kernel::loadSchedules()
   ↓
lib/data/schedule.php
local/data/schedule.php
module schedule.php
   ↓
Scheduler
```

HTTP bootstrap не должен автоматически запускать schedule.

---

## Практические правила архитектуры

### Обычный класс

Использует constructor injection:

```php
final class Service
{
    public function __construct(
        private Repository $repository
    ) {}
}
```

### Composition root

Может обращаться к Kernel:

```php
$kernel = Kernel::instance();
$scheduler = $kernel->get(Scheduler::class);
```

### Runtime infrastructure

Может использовать `ServiceLocator::make()` для class-string объектов с runtime context.

### F4

Используется как удобный framework facade и доступ к framework-state, но не как универсальный DI container.

### Environment

Единственный источник HTTP environment/request normalization.

### ErrorHandler

Единственная конечная система обработки необработанных ошибок.

### F4Store

Единственный владелец общего key-value framework state.

---

## Что ещё остаётся на следующих этапах

Текущий Stage 2 ещё не считается полностью закрытым. После стабилизации текущих контрактов планируются:

- окончательное отделение bootstrap/runtime логики от `F3Helpers`;
- дальнейшее уменьшение ответственности `F4`;
- последовательное превращение cookie/session/cache facade-методов в чистые provider-прокси;
- аудит всех сайтов на старые `F4::instance()`, `getDI()`, `hasDI()`;
- аудит ручных `new` для DI-managed классов;
- проверка runtime factory contracts;
- удаление оставшихся compatibility bridges;
- полный lint/runtime smoke test всех проектов.

Главный принцип этого этапа: не перепроектировать всё ядро одновременно, а последовательно отделять владельцев ответственности, сохраняя рабочий публичный API там, где он ещё полезен.
