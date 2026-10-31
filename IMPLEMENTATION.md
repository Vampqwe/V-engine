# IMPLEMENTATION.md — СТАТУС РЕАЛИЗАЦИИ ДВИЖКА

**Версия:** 1.0.0
**Дата:** 01 ноября 2026
**Основано на:** CONSTITUTION.md v4.0.0, AI_DIRECTIVE.md v1.0.0

Этот документ содержит **только** статус реализации и план.
Принципы и контракты — в [CONSTITUTION.md](./CONSTITUTION.md). Инструкции по установке — в [README.md](./README.md).

---

## 1. ТЕКУЩЕЕ СОСТОЯНИЕ (аудит репозитория)

### Реализовано ✅
| Файл | Назначение |
|---|---|
| `core/Contract/LoggerInterface.php` | Контракт логгера (Статья 16) |
| `core/Contract/ModuleInterface.php` | Контракт модуля |
| `core/Contract/SiteContextInterface.php` | Контракт контекста сайта |
| `core/Fallback/500.html` | Аварийная страница (Статья 29) |
| `core/Kernel.php` | Заготовка точки входа (пустой класс) |
| `config/core.php`, `config/logger.php` | Конфигурация ядра и каналов логирования |
| `law/` (RuleEngine, 3 детектора, Violation, manifest) | Конституционный Суд Lex: StrictTypes, NewCoreInstantiation, UnsafeOutput |
| `bin/lex.php` | Запуск Lex |
| `public/index.php` | Заготовка точки входа сайта |

### Не реализовано ❌
- 22 из 25 контрактов (см. §3)
- Все 31 модуль уровня P0–P3 (ядро не запускается)
- `Registry`, `Container`, `EventBus`, `Router`, `helpers.php` в корне `core/`
- Каталог `tests/` и PHPUnit-конфигурация
- Детекторы Lex под новые контракты
- `core/Admin/`

---

## 2. ПРАВИЛА ПЛАНА (из Конституции и Директивы)

1. **СТРОГИЙ порядок уровней:** ШАГ 1 (P0) → ШАГ 2 (P1) → ШАГ 3 (P2) → ШАГ 4 (P3). Нельзя пропускать уровни и менять внутреннюю очерёдность (Статья 8.5).
2. Каждый шаг = контракт → реализация в `core/Module/<Name>/` → Unit-тесты → детектор Lex (если новый контракт) → `composer lex` = 0 FAIL → обновление этого файла.
3. Модуль = класс, реализующий `ModuleInterface`, регистрируется через DI. Никаких `new \Core\...` вне ядра.
4. Никаких сторонних пакетов в `require` (только ext-*; phpunit — только dev).
5. Все файлы: `declare(strict_types=1);`, 100% ООП.
6. Чего нет в Конституции — **спросить у пользователя, не придумывать**.

---

## 3. ЭТАП 0 — ДОПОЛНИТЕЛЬНЫЙ ВОПРОС (требует решения пользователя) ⚠️

В статье 8.5 перечислены 8 P0-компонентов, включая **Config**, но в Статья 19 (структура) и Статья 16 (25 контрактов) **модуля Config / ConfigInterface нет**. Это противоречие.

**Варианты (нужен выбор пользователя):**
- A) Реализовать Config как P0-компонент ядра без отдельного контракта из списка 25 (внутренний сервис `core/Module/Config/`) + добавить `ConfigInterface` в Contract/ (он уже упоминается в Статье 17 как обязательный для проверки Doctor'ом).
- B) Иное решение пользователя.

До получения ответа Config помечен как «ожидает согласования».

---

## 4. ШАГ 1 — P0: ФУНДАМЕНТ (реализуется первым)

Критерий Lex: отсутствие любого P0-модуля → **FAIL**.

| № | Компонент | Контракт | Файлы | Тесты | Статус |
|---|---|---|---|---|---|
| 1.1 | **FileStorage** | FileStorageInterface | `core/Contract/FileStorageInterface.php`, `core/Module/FileStorage/FileStorage.php` | `tests/Unit/Module/FileStorage/` | ❌ |
| 1.2 | **Logger** | LoggerInterface ✅существует | `core/Module/Logger/FileLogger.php`, каналы по `config/logger.php` (Статья 31) | `tests/Unit/Module/Logger/` | ❌ |
| 1.3 | **Config** ⚠️ см. §3 | ConfigInterface | `core/Module/Config/Config.php`, чтение `.env` + `config/*.php` | `tests/Unit/Module/Config/` | ⏸ ожидает решения |
| 1.4 | **Container (DI)** | FactoryInterface, KernelInterface::get() | `core/Container.php` | `tests/Unit/ContainerTest.php` | ❌ |
| 1.5 | **EventBus** | (событийная модель, Статья 18) | `core/EventBus.php` | `tests/Unit/EventBusTest.php` | ❌ |
| 1.6 | **Registry** | ModuleInterface ✅существует | `core/Registry.php` | `tests/Unit/RegistryTest.php` | ❌ |
| 1.7 | **Router** | (внутренний, без контракта из 25) | `core/Router.php` | `tests/Unit/RouterTest.php` | ❌ |
| 1.8 | **Kernel** | KernelInterface | `core/Kernel.php` (дописать), `core/helpers.php` (`e()`), `public/index.php` | `tests/Unit/KernelTest.php` | ❌ |

**Выход из ШАГА 1:** ядро стартует, `composer lex` = 0 FAIL, все тесты зелёные.

---

## 5. ШАГ 2 — P1: БЕЗОПАСНОСТЬ И БАЗА

Критерий Doctor/Lex: отсутствие P1-модуля → **FAIL**.

| № | Модуль | Контракт | Особые требования | Статус |
|---|---|---|---|---|
| 2.1 | **Security** | (CSRF/XSS — автоматика) | CSRF-токены, проверка POST/PUT/PATCH/DELETE молча; `e()` в helpers; rate limiting (Статья 4) | ❌ |
| 2.2 | **Session** | SessionInterface | Регенерация ID, привязка IP/User-Agent; прямой `$_SESSION` запрещён | ❌ |
| 2.3 | **Cookie** | CookieInterface | Signed cookies, SameSite, HttpOnly, Secure | ❌ |
| 2.4 | **Database** | DatabaseInterface | PDO prepared statements; `find/insert/update/delete`; пароль Argon2id; `$pdo->query()` наружу не отдаём | ❌ |
| 2.5 | **View** | ViewInterface | Нативные PHP-шаблоны (Статья 34), компиляторы запрещены; вывод только через `e()` | ❌ |
| 2.6 | **Cache** | CacheInterface | Файловый драйвер поверх FileStorage | ❌ |
| 2.7 | **ErrorHandler** | HealthCheckInterface | `set_error_handler`, изолированный канал лога (Статья 32) | ❌ |
| 2.8 | **ExceptionHandler** | HealthCheckInterface | `set_exception_handler`, Fallback 500.html при отказе собственного обработчика (Статья 29) | ❌ |

**Детекторы Lex (новые):** вызов `htmlspecialchars` вне ядра; прямой доступ `$_SESSION/$_COOKIE`; `->query(` с конкатенацией; отсутствие CSRF-middleware в pipeline.

---

## 6. ШАГ 3 — P2: ПРОДВИНУТАЯ ИНФРАСТРУКТУРА

Критерий Doctor: отсутствие P2-модуля → **WARN**.

| № | Модуль | Контракт | Зависимости | Статус |
|---|---|---|---|---|
| 3.1 | **Validator** | ValidatorInterface, ValidationResult | — | ❌ |
| 3.2 | **Paginator** | PaginatorInterface, PaginationResult | Database | ❌ |
| 3.3 | **FileUpload** | FileUploadInterface | FileStorage, Security | ❌ |
| 3.4 | **Queue** | QueueInterface, JobInterface | Database/Cache | ❌ |
| 3.5 | **Scheduler** | SchedulerInterface | Queue | ❌ |
| 3.6 | **Notification** | NotificationInterface | Logger (канал), Queue | ❌ |
| 3.7 | **AuditLog** | AuditLogInterface | Database, EventBus | ❌ |
| 3.8 | **Migration** | MigrationInterface, MigrationManager | Database | ❌ |
| 3.9 | **Seeder** | SeederInterface, SeederManager | Migration, Database | ❌ |

---

## 7. ШАГ 4 — P3: УПРАВЛЕНИЕ И НАБЛЮДАЕМОСТЬ

Отсутствие P3 — не ошибка (но реализуем полностью для «полноценной ОС»).

| № | Модуль | Контракт | Примечание | Статус |
|---|---|---|---|---|
| 4.1 | **FeatureFlag** | FeatureFlagInterface | — | ❌ |
| 4.2 | **MaintenanceMode** | MaintenanceModeInterface | — | ❌ |
| 4.3 | **GracefulShutdown** | GracefulShutdownInterface | — | ❌ |
| 4.4 | **Doctor** | HealthCheckInterface | Проверяет ВСЕ модули явно, без reflection (Статья 7); группы P0/P1/P2 | ❌ |
| 4.5 | **DataPortability** | (Статья 33) | Экспорт/импорт БД + файлов | ❌ |
| 4.6 | **Admin** | (Статья 19) | `core/Admin/{Controller,View,assets}` — изолированная админка | ❌ |

---

## 8. КОНТРАКТЫ: ЧЕКЛИСТ 25 ШТУК (Статья 16)

| Контракт | Статус | | Контракт | Статус |
|---|---|---|---|---|
| DatabaseInterface | ❌ | | NotificationInterface | ❌ |
| ViewInterface | ❌ | | AuditLogInterface | ❌ |
| SessionInterface | ❌ | | FeatureFlagInterface | ❌ |
| CookieInterface | ❌ | | MaintenanceModeInterface | ❌ |
| FileStorageInterface | ❌ | | GracefulShutdownInterface | ❌ |
| CacheInterface | ❌ | | MigrationInterface | ❌ |
| LoggerInterface | ✅ | | SeederInterface | ❌ |
| ValidatorInterface | ❌ | | HealthCheckInterface | ❌ |
| PaginatorInterface | ❌ | | ModuleInterface | ✅ |
| FileUploadInterface | ❌ | | KernelInterface | ❌ |
| QueueInterface | ❌ | | TestableInterface | ❌ |
| SchedulerInterface | ❌ | | FactoryInterface | ❌ |
| SiteContextInterface | ✅ | | (+ вложенные: ValidationResult, PaginationResult, JobInterface, Notification, MigrationManager, SeederManager) | ❌ |

Текущий прогресс: **3 / 25**.

---

## 9. LEX (law/) — РАСШИРЕНИЕ ДЕТЕКТОРОВ

Существующие: StrictTypesDetector ✅, NewCoreInstantiationDetector ✅, UnsafeOutputDetector ✅.

Запланированные (добавлять синхронно с новым контрактом, регистрировать в `law/manifest.php`):
- DirectSuperglobalDetector ($_GET/$_POST/$_SESSION/$_COOKIE вне ядра) — к ШАГУ 2
- ManualSecurityDetector (htmlspecialchars / csrf_verify в пользовательском коде) — к ШАГУ 2
- RawPdoQueryDetector ($pdo->query/prepare вне Database-модуля) — к ШАГУ 2
- MissingModuleDetector (проверка наличия P0→FAIL, P1→FAIL по Статье 8) — к ШАГУ 1
- TemplateCompilerDetector (запрет компиляторов шаблонов) — к ШАГУ 2

---

## 10. ТЕСТЫ (Статья 21)

```
tests/
├── Unit/           # каждый модуль — своя папка, <Name>Test.php
│   └── Law/        # тесты самих детекторов Lex
├── Integration/    # связки: Kernel+Container+Router, Database+Migration
└── Fixtures/       # временные ФС, .env-образцы, sqlite-базы
```
- `phpunit.xml.dist` создать на ШАГЕ 1.
- Покрытие: каждый публичный метод контракта ≥ 1 позитивный + ≥ 1 негативный тест.
- Границы: никаких внешних сервисов; Database-тесты на SQLite in-memory; файловые — на tmp-папке с cleanup.

---

## 11. КОНТРОЛЬНЫЕ ТОЧКИ (Definition of Done)

- [ ] **DoD-1 (конец ШАГА 1):** `php public/index.php` поднимает ядро; ответ Router'а; `composer lex` = 0 FAIL; `composer test` зелёные.
- [ ] **DoD-2 (конец ШАГА 2):** CSRF/XSS/SQLi-защита работает молча; Doctor ещё нет, но Lex проверяет наличие P1; интеграционный тест «запрос → БД → шаблон → ответ».
- [ ] **DoD-3 (конец ШАГА 3):** очередь + планировщик выполняют job; миграция применяется/откатывается; Lex = 0 FAIL, Doctor (когда появится) = 0 WARN по P2.
- [ ] **DoD-4 (конец ШАГА 4):** Doctor даёт полный отчёт; Admin доступна; DataPortability делает round-trip экспорт→импорт; полный `composer lex && composer test` = зелёные; README актуален.

---

## 12. ЖУРНАЛ ИЗМЕНЕНИЙ ПЛАНА

| Дата | Событие |
|---|---|
| 01.11.2026 | План создан. Аудит: 3/25 контрактов, 0/31 модуль. Открыт вопрос §3 (Config). |
