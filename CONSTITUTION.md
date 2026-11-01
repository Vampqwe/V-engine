# CONSTITUTION.md — КОНСТИТУЦИЯ ДВИЖКА

## ПРЕАМБУЛА

Этот документ является **высшим законом** движка. Все решения, весь код, вся архитектура подчиняются принципам, изложенным здесь. Нарушение конституции = нарушение целостности системы.

**Этот документ НЕ содержит:**
- Статус реализации (см. [IMPLEMENTATION.md](./IMPLEMENTATION.md))
- Инструкции по установке (см. [README.md](./README.md))
- Графические чертежи (см. [README.md](./README.md))
- Инструкции для ИИ (см. [AI_DIRECTIVE.md](./AI_DIRECTIVE.md))

**Этот документ содержит:**
- Непоколебимые принципы
- Правила кода
- 25 контрактов (интерфейсов)
- Запреты и ограничения
- 6 теорем архитектуры
- Классификацию модулей и приоритеты реализации

**Версия:** 4.0.0  
**Дата:** 31 октября 2026  
**Статус:** Действует

---

## РАЗДЕЛ I. ФУНДАМЕНТАЛЬНЫЕ ПРИНЦИПЫ

### Статья 1. Движок — это операционная система

Движок не является продуктом. Движок — это **инфраструктура** для создания продуктов. Ядро не знает о "блогах", "магазинах", "форумах". Ядро знает только о:
- Жизненном цикле HTTP-запроса
- Безопасности
- Хранении данных
- Рендеринге шаблонов
- Самодиагностике
- Фоновых задачах
- Уведомлениях
- Эволюции схемы данных

### Статья 2. Две дороги

Существует ровно **два пути** выполнения кода:

1. **Путь Ядра** — инфраструктура, безопасность, автоматическая защита
2. **Путь Пользователя** — бизнес-логика, контент, специфика сайта

Эти пути **не пересекаются** напрямую. Пересечение происходит только через **Точки Взаимодействия** (контракты, события, DI-контейнер).

### Статья 3. Контракты вместо реализаций

Пользователь **никогда** не видит внутреннюю реализацию ядра. Пользователь работает только с **интерфейсами** (контрактами).

**ПРАВИЛЬНО:**
```php
declare(strict_types=1);

$db = $container->get(DatabaseInterface::class);
$db->find('users', ['id' => 1]);
```

**НЕПРАВИЛЬНО:**
```php
$db = new \Core\Database\PdoDriver(); // ❌ Запрещено
$db->query('SELECT * FROM users WHERE id = 1'); // ❌ Запрещено
```

### Статья 4. Автоматическая безопасность

**Две разные вещи, которые нельзя путать:**

#### 4.1. Обязательная реализация (ядро ДОЛЖНО иметь)

Ядро **обязано** реализовать и применять **автоматически**:
- CSRF-защиту (генерация токенов, проверка в POST/PUT/DELETE)
- XSS-экранирование вывода (через функцию `e()`)
- Защиту от SQL-инъекций (prepared statements в `DatabaseInterface`)
- Валидацию входных данных (типы, длина, формат)
- Rate limiting (ограничение частоты запросов)
- Защиту сессий (регенерация ID, привязка к IP/User-Agent)
- Хеширование паролей (`password_hash` с Argon2id)

**Всё это работает МОЛЧА, без участия пользователя.**

#### 4.2. Запрет на ручной доступ (пользователь НЕ ДОЛЖЕН трогать)

Пользователю **запрещено**:
- Вызывать `csrf->verify()` напрямую
- Писать `htmlspecialchars()` вручную
- Писать `$pdo->prepare()` напрямую
- Реализовывать свою проверку CSRF

**Почему:** Ручная безопасность = человеческие ошибки. Ядро делает это надёжнее.

#### 4.3. Критерий корректности (как проверяет Lex)

✅ **Правильно:**
- В `core/Module/Security/` есть полная реализация CSRF, XSS-защиты
- Middleware Pipeline автоматически применяет защиту ко всем запросам
- Пользовательский код не содержит `htmlspecialchars`, `csrf_verify`

❌ **Неправильно:**
- Отсутствует реализация CSRF "потому что она автоматическая"
- Пользователь пишет свою проверку CSRF в контроллере

### Статья 5. Опциональность БД vs обязательность драйвера

**Две разные вещи, которые нельзя путать:**

#### 5.1. Обязательная реализация (ядро ДОЛЖНО иметь)

Ядро **обязано** предоставлять полную реализацию работы с БД:
- `DatabaseInterface` с методами `insert`, `find`, `findAll`, `update`, `delete`, `transaction`
- PDO-драйвер внутри модуля `Core\Module\Database`
- Поддержка MySQL/MariaDB, PostgreSQL, SQLite
- Prepared statements (защита от SQL-инъекций)
- Транзакции
- Connection pool (опционально)

**Это НЕ опционально.** Без этого движок неполноценен.

#### 5.2. Опциональное использование (пользователь МОЖЕТ не настраивать)

Пользователь **имеет право** не настраивать БД в `.env`. В этом случае:
- Ядро стартует **без фатальной ошибки**
- Модуль `Database` переходит в режим "неактивен"
- Вызов `$db->find()` бросает понятное исключение: "Database not configured"
- Doctor выдаёт **Warning** (не Fail): "БД не настроена. Работает только статика."
- Статические сайты, лендинги, документация работают полноценно

**Это опционально.** Это право пользователя, не обязанность ядра.

#### 5.3. Критерий корректности (как проверяет Lex)

✅ **Правильно:**
- В `core/Module/Database/` есть полная реализация PDO-драйвера
- В `public/index.php` есть проверка: если БД не настроена — не падаем
- В `Doctor` есть health-check: "БД настроена? Да/Нет/Ошибка"

❌ **Неправильно:**
- Отсутствует реализация PDO-драйвера "потому что БД опциональна"
- Ядро падает при старте, если нет подключения к БД
- Doctor не проверяет состояние БД

### Статья 6. Админка — изолированный бог

Админка существует **отдельно** от пути пользователя. Админка:
- Имеет отдельный роутер (`/admin/*`)
- Имеет отдельную авторизацию (`SuperAdminGuard`)
- Видит всё, но никто не трогает её
- Работает как SPA (статика + API)

### Статья 7. Самодиагностика (Doctor)

**Две разные вещи:**

#### 7.1. Обязательная реализация (ядро ДОЛЖНО иметь)

Ядро **обязано** реализовать:
- Модуль `Core\Module\Doctor`
- CLI-команду `php bin/doctor check`
- HTTP-эндпоинт `GET /system/health`
- Контракт `HealthCheckInterface`
- Базовые health-checks для всех модулей ядра (БД, кэш, сессии, файлы)

**Это НЕ опционально.** Без Doctor движок не production-ready.

#### 7.2. Наблюдающий принцип (как Doctor работает)

Doctor **НЕ ДОЛЖЕН**:
- Использовать reflection для "шпионажа" за модулями
- Знать внутреннюю реализацию модулей
- Пытаться угадать состояние системы

Doctor **ДОЛЖЕН**:
- Собирать отчёты через `HealthCheckInterface`
- Каждый модуль сам отчитывается о своём здоровье
- Doctor только агрегирует и выводит отчёт

#### 7.3. Критерий корректности (как проверяет Lex)

✅ **Правильно:**
- В `core/Module/Doctor/` есть полная реализация
- Каждый модуль ядра реализует `HealthCheckInterface`
- `php bin/doctor check` работает без запущенного ядра (для Lint-проверок)

❌ **Неправильно:**
- Отсутствует реализация Doctor "потому что он наблюдающий"
- Doctor использует reflection для проверки модулей

### Статья 8. Классификация модулей и приоритеты реализации

Все модули ядра делятся на **4 уровня обязательности**. Это не рекомендация — это **закон реализации**.

#### 8.1. P0 — ФУНДАМЕНТ (обязательно, реализуется первым)

Без этих модулей ядро **не запустится вообще**:

**Инфраструктурные компоненты ядра** (файлы в корне `core/`, не в `core/Module/`):
1. **Container** — DI-контейнер (ПЕРВЫЙ, задаёт паттерн для всех модулей)
2. **EventBus** — событийная шина
3. **Registry** — реестр модулей
4. **Router** — маршрутизатор
5. **Kernel** — точка входа, оркестратор

**Модули P0** (в `core/Module/`):
1. **FileStorage** — работа с файловой системой
2. **Logger** — логирование через каналы
3. **Config** — чтение `.env` и `config/*.php`

**Критерий Lex:** Если отсутствует хотя бы один P0-компонент → **FAIL**.

#### 8.2. P1 — БЕЗОПАСНОСТЬ И БАЗА (обязательно для production)

Без этих модулей сайт **уязвим или неработоспособен**:

1. **Security** — CSRF, XSS, автоматическая защита
2. **Session** — сессии пользователей
3. **Cookie** — работа с куки
4. **Database** — PDO-драйвер
5. **View** — рендеринг шаблонов
6. **Cache** — кэширование
7. **ErrorHandler** — перехват ошибок PHP
8. **ExceptionHandler** — перехват исключений

**Критерий Lex:** Если отсутствует P1-модуль → Doctor выдаёт **FAIL**.

#### 8.3. P2 — ПРОДВИНУТАЯ ИНФРАСТРУКТУРА (обязательно для полноценного движка)

Без этих модулей движок **неполноценен**:

1. **Validator** — валидация данных
2. **Paginator** — пагинация
3. **FileUpload** — загрузка файлов
4. **Queue** — фоновые задачи
5. **Scheduler** — планировщик
6. **Notification** — уведомления
7. **AuditLog** — журнал действий
8. **Migration** — миграции БД
9. **Seeder** — тестовые данные

**Критерий Lex:** Если отсутствует P2-модуль → Doctor выдаёт **WARN**.

#### 8.4. P3 — УПРАВЛЕНИЕ И НАБЛЮДАЕМОСТЬ (желательно)

Эти модули **улучшают** движок:

1. **FeatureFlag** — управление фичами
2. **MaintenanceMode** — режим техработ
3. **GracefulShutdown** — плавная остановка
4. **Doctor** — самодиагностика
5. **DataPortability** — экспорт/импорт
6. **Admin** — админ-панель

**Критерий Lex:** Отсутствие P3 — не ошибка.

#### 8.5. Порядок реализации (СТРОГО)

```
ШАГ 1: Container (задаёт паттерн DI для всех модулей)
ШАГ 2: FileStorage → Logger → Config (P0-модули)
ШАГ 3: EventBus → Registry → Router → Kernel (инфраструктура)
ШАГ 4: P1-модули (Security → Session → Cookie → Database → View → Cache → ErrorHandler → ExceptionHandler)
ШАГ 5: P2-модули (Validator → Paginator → FileUpload → Queue → Scheduler → Notification → AuditLog → Migration → Seeder)
ШАГ 6: P3-модули (FeatureFlag → MaintenanceMode → GracefulShutdown → Doctor → DataPortability → Admin)
```

**Нельзя пропускать уровни.** Нельзя реализовать Database (P1) до FileStorage (P0).

#### 8.6. Зависимости между модулями

Каждый модуль указывает свои зависимости в `getDependencies()`:

```php
class DatabaseModule implements ModuleInterface {
    public function getDependencies(): array {
        return [
            ConfigInterface::class,      // P0
            LoggerInterface::class,      // P0
        ];
    }
}
```

**Lex проверяет:** Все зависимости модуля должны быть реализованы **до** него.

#### 8.7. Запрет на "пропуск базовых модулей"

**КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:**
- Пропускать FileStorage "потому что это очевидно"
- Пропускать Logger "потому что можно потом добавить"
- Пропускать Config "потому что .env можно читать напрямую"
- Пропускать Container "потому что можно использовать new"
- Реализовывать Database до FileStorage

**Почему:** Это фундамент. Без него всё развалится.

---

## РАЗДЕЛ II. ПРАВИЛА КОДА

### Статья 9. Строгий типизированный код

**Обязательно:**
```php
declare(strict_types=1);
```

В **каждом** PHP-файле. Без исключений.

### Статья 10. 100% ООП

Весь код — объектно-ориентированный. Запрещены:
- Глобальные функции (кроме автозагрузки и хелперов в `core/helpers.php`)
- Процедурный стиль
- Смешивание логики и представления в одном классе

### Статья 11. Запрет прямого создания объектов ядра

**КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО** в пользовательском пространстве (`public/*/module/`):
```php
$fs = new \Core\Module\FileSystem(); // ❌
$db = new \Core\Database\Connection(); // ❌
$view = new \Core\View\Template(); // ❌
```

**ЕДИНСТВЕННЫЙ ПРАВИЛЬНЫЙ СПОСОБ:**
```php
$fs = $container->get(FileStorageInterface::class);
$db = $container->get(DatabaseInterface::class);
$view = $container->get(ViewInterface::class);
```

### Статья 11.1. Composition Root

**Исключение из Статьи 11.** Единственные места, где допустимо конструировать объекты ядра:
- `core/Kernel::boot()`
- `law/bin/*` (Конституционный Суд)
- Фабрики в `Container::set()`

Пользовательское пространство (`public/*/module`) не имеет этого исключения никогда.

### Статья 12. Единая точка регистрации модулей

**ЗАПРЕЩЕНО:**
```php
$registry->modules[] = new MyModule(); // ❌
$registry->add($module); // ❌
```

**РАЗРЕШЕНО:**
```php
$kernel->addUserModule(new MyModule()); // ✅ Единственный способ
```

### Статья 13. Запрет смешивания ответственности

В одной папке/модуле **запрещено** хранить:
- Разнородные сущности (логика + шаблоны + стили в одном месте)
- Код, не относящийся к модулю
- Временные файлы, логи, кэш

**ПРАВИЛЬНАЯ СТРУКТУРА:**
```
public/mysite.by/
  ├── /assets     (только CSS, JS, IMG)
  ├── /view       (только шаблоны HTML)
  └── /module     (только PHP-логика)
```

### Статья 14. Интерфейсы превыше всего

Если функционал нужен часто — создай **интерфейс**. Если интерфейс есть — используй **только** его.

**ЗАПРЕЩЕНО:**
```php
class UserController {
    public function __construct() {
        $this->db = new PdoDriver(); // ❌ Конкретная реализация
    }
}
```

**ПРАВИЛЬНО:**
```php
declare(strict_types=1);

class UserController {
    public function __construct(
        private DatabaseInterface $db // ✅ Контракт
    ) {}
}
```

### Статья 15. Чистота модулей

В папку модуля **запрещено** класть что-то разное и непонятное. Каждый модуль — это **одна ответственность**. Если функционал не вписывается — создай новый модуль или предложи его ядру.

---

## РАЗДЕЛ III. КОНТРАКТЫ ЯДРА

### Статья 16. Обязательные интерфейсы (25 контрактов)

Ядро предоставляет следующие контракты (пользователь видит **только** их):

#### 16.1. Базовые контракты

```php
declare(strict_types=1);

interface DatabaseInterface {
    public function insert(string $table, array $data): int;
    public function find(string $table, array $criteria): ?array;
    public function findAll(string $table, array $criteria = []): array;
    public function update(string $table, array $data, array $criteria): int;
    public function delete(string $table, array $criteria): int;
    public function transaction(callable $callback): mixed;
}

interface ViewInterface {
    public function render(string $template, array $data = []): string;
    public function exists(string $template): bool;
}

interface SessionInterface {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    public function destroy(): void;
    public function regenerate(): void;
}

interface CookieInterface {
    public function get(string $name): ?string;
    public function set(string $name, string $value, int $ttl = 0): void;
    public function remove(string $name): void;
    public function has(string $name): bool;
}

interface FileStorageInterface {
    public function load(string $path): ?string;
    public function save(string $path, string $content): bool;
    public function delete(string $path): bool;
    public function exists(string $path): bool;
    public function createDir(string $path): bool;
    public function list(string $pattern): array;
}

interface CacheInterface {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function has(string $key): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
}

interface LoggerInterface {
    public function channel(string $name): LoggerInterface;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
}
```

#### 16.2. Контракты данных и валидации

```php
declare(strict_types=1);

interface ValidatorInterface {
    public function validate(array $data, array $rules): ValidationResult;
}

interface ValidationResult {
    public function isValid(): bool;
    public function getErrors(): array;
    public function getValidatedData(): array;
}

interface PaginatorInterface {
    public function paginate(int $page, int $perPage, array $criteria = []): PaginationResult;
}

interface PaginationResult {
    public function getItems(): array;
    public function getTotal(): int;
    public function getPages(): int;
    public function getCurrentPage(): int;
    public function hasMore(): bool;
    public function getLinks(): array;
}

interface FileUploadInterface {
    public function upload(UploadedFile $file, string $destination): UploadResult;
    public function validate(UploadedFile $file, array $rules): ValidationResult;
}
```

#### 16.3. Контракты фоновых задач

```php
declare(strict_types=1);

interface QueueInterface {
    public function push(string $job, array $payload = []): string;
    public function pushDelayed(string $job, array $payload, int $delay): string;
    public function pushToTube(string $tube, string $job, array $payload = []): string;
    public function count(): int;
    public function retry(string $jobId): bool;
    public function clear(?string $tube = null): void;
}

interface JobInterface {
    public function handle(array $payload): void;
    public function failed(\Throwable $exception): void;
    public function retries(): int;
}

interface SchedulerInterface {
    public function schedule(string $job, string $expression): ScheduledTask;
    public function run(): void;
    public function list(): array;
}
```

#### 16.4. Контракты коммуникации

```php
declare(strict_types=1);

interface NotificationInterface {
    public function send(Notification $notification, array $recipients): void;
    public function sendLater(Notification $notification, array $recipients, int $delay): void;
    public function getChannels(): array;
}

interface Notification {
    public function via(): array;
    public function toEmail(Recipient $recipient): EmailMessage;
    public function toSms(Recipient $recipient): SmsMessage;
    public function toPush(Recipient $recipient): PushMessage;
}
```

#### 16.5. Контракты наблюдаемости

```php
declare(strict_types=1);

interface AuditLogInterface {
    public function log(string $action, array $context = []): void;
    public function getLogs(array $criteria = [], int $limit = 100): array;
    public function getUserActions(int $userId, int $limit = 50): array;
}

interface HealthCheckInterface {
    public function getName(): string;
    public function getGroup(): HealthGroup; // P0, P1, P2
    public function check(): HealthCheckResult;
}

enum HealthGroup: string {
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';
}

enum HealthStatus: string {
    case OK = 'ok';
    case WARN = 'warn';
    case FAIL = 'fail';
}

class HealthCheckResult {
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $message,
        public readonly ?string $hint = null,
        public readonly array $details = []
    ) {}
}
```

#### 16.6. Контракты управления

```php
declare(strict_types=1);

interface FeatureFlagInterface {
    public function isEnabled(string $feature): bool;
    public function enable(string $feature): void;
    public function disable(string $feature): void;
    public function getAll(): array;
}

interface MaintenanceModeInterface {
    public function enable(?string $message = null, array $allowedIps = []): void;
    public function disable(): void;
    public function isEnabled(): bool;
    public function getMessage(): ?string;
}

interface GracefulShutdownInterface {
    public function register(callable $callback): void;
    public function shutdown(int $timeout = 30): void;
    public function isShuttingDown(): bool;
}
```

#### 16.7. Контракты эволюции данных

```php
declare(strict_types=1);

interface MigrationInterface {
    public function up(): void;
    public function down(): void;
}

interface MigrationManager {
    public function migrate(): void;
    public function rollback(int $steps = 1): void;
    public function status(): array;
    public function create(string $name): string;
}

interface SeederInterface {
    public function run(): void;
}

interface SeederManager {
    public function seed(?string $seeder = null): void;
    public function list(): array;
}
```

#### 16.8. Контракты модульности

```php
declare(strict_types=1);

interface ModuleInterface {
    public function getName(): string;
    public function getVersion(): string;
    public function boot(KernelInterface $kernel): void;
    public function getDependencies(): array;
}

interface KernelInterface {
    public function addUserModule(ModuleInterface $module): void;
    public function getContainer(): ContainerInterface;
    public function getEventBus(): EventBusInterface;
    public function get(string $interface): mixed;
}
```

#### 16.9. Контракты тестирования

```php
declare(strict_types=1);

interface TestableInterface {
    public function getUnitTests(): array;
    public function getIntegrationTests(): array;
    public function getFixtures(): array;
}

interface FactoryInterface {
    public function create(string $entity, array $overrides = []): array;
    public function make(int $count, string $entity, array $overrides = []): array;
}
```

#### 16.10. Контекст сайта

```php
declare(strict_types=1);

interface SiteContextInterface {
    public function getBaseFolder(): string;
    public function getBaseUrl(): string;
    public function getEnvPath(): string;
    public function getConfigPath(): string;
}
```

---

## РАЗДЕЛ IV. СОБЫТИЙНАЯ МОДЕЛЬ

### Статья 17. Хуки ядра

Ядро предоставляет следующие события (пользователь может **слушать**, но не **триггерить**):

```php
// Жизненный цикл запроса
'onRequestStart'      // До обработки
'onRouteResolved'     // После маршрутизации
'onBeforeRender'      // Перед рендерингом
'onAfterRender'       // После рендеринга
'onRequestEnd'        // После отправки ответа
'onError'             // При ошибке

// Файловая система
'onFileLoad'          // Перед загрузкой файла
'onFileSave'          // Перед сохранением файла
'onDirCreate'         // Перед созданием папки

// Самодиагностика
'health.check'        // Запрос проверки здоровья модуля
```

### Статья 18. Регистрация слушателей

**ПРАВИЛЬНО:**
```php
declare(strict_types=1);

class MyModule implements ModuleInterface {
    public function boot(KernelInterface $kernel): void {
        $kernel->getEventBus()->listen('onRouteResolved', function($route) {
            // Логика
        });
    }
}
```

**НЕПРАВИЛЬНО:**
```php
$kernel->dispatch('onRouteResolved'); // ❌ Пользователь не триггерит события ядра
```

---

## РАЗДЕЛ V. СТРУКТУРА ПРОЕКТА

### Статья 19. Обязательная структура ядра

```
core/
  ├── Kernel.php              ← P0 инфраструктура
  ├── Registry.php            ← P0 инфраструктура
  ├── Container.php           ← P0 инфраструктура (ПЕРВЫЙ!)
  ├── EventBus.php            ← P0 инфраструктура
  ├── Router.php              ← P0 инфраструктура
  ├── helpers.php             ← Глобальные хелперы: e(), etc.
  │
  ├── Contract/               ← 25+ интерфейсов (см. ERRATA)
  │   ├── DatabaseInterface.php
  │   ├── ViewInterface.php
  │   ├── SessionInterface.php
  │   ├── CookieInterface.php
  │   ├── FileStorageInterface.php
  │   ├── CacheInterface.php
  │   ├── LoggerInterface.php
  │   ├── ConfigInterface.php         ← ERRATA-001
  │   ├── ContainerInterface.php      ← ERRATA-002
  │   ├── EventBusInterface.php       ← ERRATA-003
  │   ├── RouterInterface.php         ← ERRATA-004
  │   ├── KernelInterface.php
  │   ├── ValidatorInterface.php
  │   ├── PaginatorInterface.php
  │   ├── FileUploadInterface.php
  │   ├── QueueInterface.php
  │   ├── SchedulerInterface.php
  │   ├── NotificationInterface.php
  │   ├── AuditLogInterface.php
  │   ├── FeatureFlagInterface.php
  │   ├── MaintenanceModeInterface.php
  │   ├── GracefulShutdownInterface.php
  │   ├── MigrationInterface.php
  │   ├── SeederInterface.php
  │   ├── HealthCheckInterface.php
  │   ├── ModuleInterface.php
  │   ├── TestableInterface.php
  │   ├── FactoryInterface.php
  │   └── SiteContextInterface.php
  │
  ├── Module/                 ← Модули (P0/P1/P2/P3)
  │   ├── FileStorage/        ← P0
  │   ├── Logger/             ← P0
  │   ├── Config/             ← P0
  │   ├── Database/           ← P1
  │   ├── Security/           ← P1
  │   ├── Session/            ← P1
  │   ├── Cookie/             ← P1
  │   ├── View/               ← P1
  │   ├── Cache/              ← P1
  │   ├── ErrorHandler/       ← P1
  │   ├── ExceptionHandler/   ← P1
  │   ├── Validator/          ← P2
  │   ├── Paginator/          ← P2
  │   ├── FileUpload/         ← P2
  │   ├── Queue/              ← P2
  │   ├── Scheduler/          ← P2
  │   ├── Notification/       ← P2
  │   ├── AuditLog/           ← P2
  │   ├── Migration/          ← P2
  │   ├── Seeder/             ← P2
  │   ├── FeatureFlag/        ← P3
  │   ├── MaintenanceMode/    ← P3
  │   ├── GracefulShutdown/   ← P3
  │   ├── Doctor/             ← P3
  │   └── DataPortability/    ← P3
  │
  ├── Fallback/
  │   └── 500.html            ← Аварийная страница
  │
  └── Admin/                  ← Изолированная админка
      ├── Controller/
      ├── View/
      └── assets/
```

### Статья 20. Структура пользовательского сайта

```
public/mysite.by/
  ├── .env                    (конфигурация сайта)
  │
  ├── assets/                 (только статика)
  │   ├── css/
  │   ├── js/
  │   └── img/
  │
  ├── view/                   (только шаблоны .phtml)
  │   ├── layouts/
  │   ├── pages/
  │   └── partials/
  │
  ├── module/                 (только логика)
  │   ├── Controller/
  │   ├── Model/
  │   └── Service/
  │
  └── storage/                (закрытая папка)
      ├── cache/
      ├── logs/
      └── sessions/
```

### Статья 21. Структура тестов

```
tests/
  ├── Unit/                   (модульные тесты)
  ├── Integration/            (интеграционные тесты)
  ├── Fixtures/               (тестовые данные)
  └── Factories/              (фабрики сущностей)
```

---

## РАЗДЕЛ VI. ЗАПРЕТЫ И ОГРАНИЧЕНИЯ

### Статья 22. Абсолютные запреты

**КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:**

1. Прямое создание объектов ядра (`new \Core\...`) в пользовательском пространстве
2. Использование `global` переменных
3. Использование `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE` напрямую (только через контракты)
4. Прямые SQL-запросы (`$pdo->query()`)
5. Ручное экранирование XSS (`htmlspecialchars()` в пользовательском коде)
6. Ручная проверка CSRF (`if ($_POST['csrf'] === ...)`)
7. Хранение паролей в открытом виде
8. Вывод ошибок в production (`ini_set('display_errors', 1)`)
9. Использование `eval()`, `exec()`, `system()`
10. Смешивание HTML и PHP в одном файле (кроме шаблонов View)
11. Отсутствие `declare(strict_types=1);` в любом PHP-файле
12. Процедурный код вне автозагрузки и хелперов
13. Хранение разнородных сущностей в одной папке модуля
14. Прямой доступ к Redis, PDO, cURL
15. Обход DI-контейнера
16. Вывод в шаблонах без `e()` (правило C-040)

### Статья 23. Ограничения доступа

**Пользователь НЕ ИМЕЕТ доступа к:**

- Внутренней реализации PDO
- Напрямую к Redis
- Файловой системе вне `FileStorageInterface`
- Функциям безопасности ядра
- Админ-панели (кроме как через SuperAdmin)
- Конфигурации других сайтов
- Внутренним событиям ядра (только публичным хукам)

---

## РАЗДЕЛ VII. РАСШИРЯЕМОСТЬ

### Статья 24. Создание пользовательских модулей

**ПРАВИЛЬНЫЙ СПОСОБ:**
```php
declare(strict_types=1);

class MyCustomModule implements ModuleInterface {
    public function getName(): string {
        return 'my-custom-module';
    }
    
    public function getVersion(): string {
        return '1.0.0';
    }
    
    public function boot(KernelInterface $kernel): void {
        $kernel->getContainer()->set(
            MyServiceInterface::class,
            fn() => new MyService($kernel->get(DatabaseInterface::class))
        );
        
        $kernel->getEventBus()->listen('onRequestStart', [$this, 'onRequest']);
    }
    
    public function getDependencies(): array {
        return [DatabaseInterface::class];
    }
    
    public function onRequest(): void {
        // Логика
    }
}

// Регистрация
$kernel->addUserModule(new MyCustomModule());
```

### Статья 25. Самодиагностика модулей

Каждый модуль **может** реализовать `HealthCheckInterface`:

```php
declare(strict_types=1);

class MyCustomModule implements ModuleInterface, HealthCheckInterface {
    public function getName(): string {
        return 'my-custom-module';
    }
    
    public function getGroup(): HealthGroup {
        return HealthGroup::P2;
    }
    
    public function check(): HealthCheckResult {
        if ($this->isHealthy()) {
            return new HealthCheckResult(
                HealthStatus::OK,
                'Модуль работает корректно'
            );
        }
        
        return new HealthCheckResult(
            HealthStatus::FAIL,
            'Модуль не работает',
            'Проверьте настройки в .env'
        );
    }
}
```

### Статья 26. Предложение новых модулей

Если пользователю не хватает функционала — он **не пихает** его в существующие папки. Он:
1. Задаёт вопрос архитектору
2. Предлагает новый модуль с контрактом
3. После одобрения — создаёт модуль по правилам

---

## РАЗДЕЛ VIII. ВЕРСИОНИРОВАНИЕ И СОВМЕСТИМОСТЬ

### Статья 27. Обратная совместимость

- Интерфейсы ядра **не ломаются** в рамках мажорной версии
- Добавление новых методов в интерфейсы = мажорная версия
- Удаление методов = мажорная версия
- Изменение сигнатур = мажорная версия

### Статья 28. Депрекейты

Устаревшие методы помечаются `@deprecated` и удаляются через **две** мажорные версии.

---

## РАЗДЕЛ IX. ТЕОРЕМЫ АРХИТЕКТУРЫ

### Теорема 1. Двух контуров валидации

> **Зелёный lint ⇒ структура корректна; зелёный doctor ⇒ среда корректна.**

Два неперекрывающихся контура валидации дают полную картину здоровья системы.

### Теорема 2. Изоляции ответственности

> **Пользовательский код не может сломать ядро.**

Все взаимодействия происходят через контракты с валидацией на уровне ядра.

### Теорема 3. Автоматической безопасности

> **Безопасность работает по умолчанию, без участия пользователя.**

CSRF, XSS, SQL-инъекции — молча, автоматически, невидимо.

### Теорема 4. Универсальности

> **Пустая БД не ломает сайт.**

Статика работает без БД. Движок универсален.

### Теорема 5. Инфраструктурной полноты

> **Пользователь не пишет инфраструктуру. Пользователь пишет бизнес-логику.**

Все 25 контрактов покрывают 100% инфраструктурных потребностей.

### Теорема 6. Модульной чистоты

> **Один модуль — одна ответственность. Никакого смешивания.**

Если функционал не вписывается — создай новый модуль.

---

## РАЗДЕЛ X. ТЕХНИЧЕСКИЕ ДОПОЛНЕНИЯ

### Статья 29. Аварийный люк (The Bunker)

В файле `public/index.php` **обязательно** наличие `register_shutdown_function` для перехвата фатальных ошибок до инициализации ядра. В случае сбоя должен отдаваться статический `core/Fallback/500.html`.

```php
define('ENGINE_BASE_PATH', dirname(__DIR__));

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        readfile(ENGINE_BASE_PATH . '/core/Fallback/500.html');
        exit(1);
    }
});
```

Любая утечка путей или трейсов в production через этот люк считается критическим нарушением (FAIL).

### Статья 30. Модульная конфигурация

Настройки не могут быть разбросаны по коду. Каждый модуль обязан иметь свой файл конфигурации в `ENGINE_BASE_PATH/config/{module}.php`. Глобальные переменные окружения сайта хранятся исключительно в `.env` внутри `SITE_BASE_FOLDER`.

```
ENGINE_BASE_PATH/
  ├── config/
  │   ├── core.php
  │   ├── logger.php
  │   ├── database.php
  │   └── {module_name}.php
  │
  └── public/mysite.by/
      └── .env  (SITE_BASE_URL, SITE_BASE_FOLDER, APP_KEY)
```

### Статья 31. Изолированные каналы логирования

Модули не имеют права напрямую работать с файловой системой для записи логов. Используется исключительно `LoggerInterface::channel('name')`. Ядро гарантирует атомарность записи и ротацию файлов для каждого канала.

```php
$logger = $container->get(LoggerInterface::class)->channel('installer');
$logger->info('Миграция успешно применена');
// Пишется в storage/logs/{date}/installer.log
```

Предустановленные каналы: `app`, `error`, `config`, `installer`, `security`, `queue`.

### Статья 32. Приоритет перехватчиков

`ErrorHandler` и `ExceptionHandler` должны быть зарегистрированы в `Kernel::boot()` **первыми**, до инициализации Роутера, БД или любых других модулей.

```php
class Kernel {
    public function boot(): void {
        // ПЕРВЫМИ — перехватчики ошибок
        $this->registerErrorHandler();
        $this->registerExceptionHandler();
        
        // ПОТОМ — всё остальное
        $this->registerRouter();
        $this->registerDatabase();
    }
}
```

### Статья 33. Портативность данных

Движок обязан предоставлять `DataPortabilityInterface` для экспорта и импорта данных. Реализация должна поддерживать потоковую обработку (streaming) для предотвращения исчерпания памяти (OOM) на больших базах.

```php
interface DataPortabilityInterface {
    public function export(string $destinationPath, array $tables = []): bool;
    public function import(string $sourcePath): bool;
}
```

### Статья 34. PHP-First подход

**Две разные вещи:**

#### 34.1. Обязательное использование (ядро ДОЛЖНО использовать)

Ядро **обязано** использовать встроенные расширения PHP:
- `PDO` — для работы с БД
- `cURL` — для HTTP-запросов
- `GD` — для обработки изображений
- `OpenSSL` / `Sodium` — для криптографии
- `DateTime` / `Intl` — для работы с датами
- `Filter` — для валидации
- `SPL` — для структур данных
- `JSON` — для парсинга JSON
- `XML` / `SimpleXML` — для парсинга XML
- `tokenizer` — для Constitutional Court (Lex)

**Это НЕ опционально.** Это основа инфраструктуры.

#### 34.2. Запрет сторонних пакетов (production)

В production-коде **запрещено** использовать сторонние пакеты:
- ❌ Guzzle, Symfony HttpClient (используем cURL)
- ❌ Monolog (пишем свой логгер на `file_put_contents`)
- ❌ Carbon (используем `DateTimeImmutable`)
- ❌ Intervention Image (используем GD)
- ❌ SwiftMailer (пишем SMTP на сокетах)
- ❌ Twig, Latte (используем нативный PHP + `e()`)

**Допустимо только для dev:**
- ✅ PHPUnit — для тестирования
- ✅ PHPStan — для статического анализа (опционально)

#### 34.3. Критерий корректности (как проверяет Lex)

✅ **Правильно:**
- В `core/Module/Database/` используется PDO
- В `core/Module/Http/` используется cURL
- В `composer.json` нет production-зависимостей

❌ **Неправильно:**
- Отсутствует реализация HTTP-клиента "потому что PHP-First"
- В `composer.json` есть `guzzlehttp/guzzle` в `require` (не в `require-dev`)

### Статья 35. Безопасный вывод в шаблонах

Шаблоны — это `.phtml` файлы с нативным PHP. Все переменные должны экранироваться через функцию `e()`. Компиляторы шаблонов запрещены.

**ПРАВИЛЬНО:**
```php
<h1><?= e($title) ?></h1>
```

**НЕПРАВИЛЬНО:**
```php
<h1><?= $title ?></h1>  <!-- ❌ Без e() -->
<h1>{{ $title }}</h1>    <!-- ❌ Компилятор запрещён -->
```

---

## РАЗДЕЛ XI. ПОСЛЕДНИЕ СЛОВА

### Статья 36. Дух конституции

Этот документ — не просто правила. Это **философия**. Каждый разработчик, работающий с движком, должен понимать:

1. Ядро — это инфраструктура, не продукт
2. Безопасность — это ответственность ядра, не пользователя
3. Контракты — это закон, реализации — детали
4. Модульность — это свобода, ограничения — это защита
5. Самодиагностика — это честность перед пользователем
6. Инфраструктура — это готово, бизнес-логика — это твоё
7. Строгий код — это уважение к будущим разработчикам
8. Нулевые зависимости — это независимость
9. Классификация P0/P1/P2/P3 — это порядок, а не рекомендация
10. Каждая статья имеет технический критерий проверки

### Статья 37. Изменения конституции

Изменения вносятся только через:
1. Обсуждение в issue
2. Голосование мейнтейнеров
3. Обновление версии (мажорной)
4. Миграционную инструкцию

---

## РАЗДЕЛ XII. ERRATA К КОНСТИТУЦИИ v4.0.0

Согласно Статье 37, Конституция не правится до мажорной версии. Расхождения фиксируются как ERRATA и будут исправлены в v5.0.0.

### ERRATA-001: ConfigInterface

**Суть:** Статья 8.1 императивно требует `Config` как P0-модуль, Статья 8.6 использует `ConfigInterface::class` в зависимостях, но интерфейс **отсутствует** в перечне Статьи 16 и в дереве Статьи 19.

**Решение:** `Config` реализуется как P0-модуль с контрактом `ConfigInterface`. Файл `core/Contract/ConfigInterface.php` создаётся по духу Статьи 3.

### ERRATA-002: ContainerInterface

**Суть:** `KernelInterface::getContainer()` возвращает `ContainerInterface`, но интерфейс **не объявлен** в Статье 16.

**Решение:** `core/Contract/ContainerInterface.php` создаётся. `core/Container.php` реализует его.

### ERRATA-003: EventBusInterface

**Суть:** `KernelInterface::getEventBus()` возвращает `EventBusInterface`, но интерфейс **не объявлен** в Статье 16.

**Решение:** `core/Contract/EventBusInterface.php` создаётся. `core/EventBus.php` реализует его.

### ERRATA-004: RouterInterface

**Суть:** Статья 8.1 относит `Router` к P0, но `RouterInterface` **не объявлен** в Статье 16.

**Решение:** `core/Contract/RouterInterface.php` создаётся. `core/Router.php` реализует его.

### Правило применения ERRATA

При реализации:
- Модули реализуются по **Статье 8** (императивный приоритет)
- Контракты создаются по **Статье 3** (дух закона)
- ERRATA не нарушают Конституцию — они дополняют её до v5.0.0

---

**Подписано:**  
Архитектор движка  
31 октября 2026 года

**Версия:** 4.0.0  
**Статус:** Действует  
**Количество статей:** 37  
**Количество контрактов:** 25 + 4 ERRATA  
**Количество теорем:** 6  
**Количество уровней модулей:** 4 (P0, P1, P2, P3)