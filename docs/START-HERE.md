# 🚀 Как запустить свой сайт на LEX Engine

> Руководство для человека, который хочет создать свой сайт. 15 минут от нуля до работающего домена.

---

## 0. Главная идея: домен = папка

В LEX **нет** реестра доменов и магии. Привязка домена к сайту — это **имя папки**:

```
sites/<домен>/   →   https://<домен>
```

| Домен запроса (HTTP_HOST) | Папка сайта |
|---|---|
| `example.com` | `sites/example.com/` (создаёте сами) |
| `demo.localhost` | `sites/demo.localhost/` |
| `v-engine` | `sites/v-engine/` |

Единая точка входа — одна на все сайты: **`public/index.php`**. Она смотрит заголовок `Host:`
виртуального хоста, находит папку `sites/<домен>` и поднимает ядро в контексте этого сайта.
Никаких `index.php` внутри папок сайтов создавать **не нужно и нельзя** (нарушение §31 — единая точка
отчёта; Закон это блокирует детекторами).

Настройки конкретного сайта живут в двух местах:
- **`sites/<домен>/.env`** — секреты и средо-зависимое (пароли БД, APP_SECRET, драйверы);
- **`sites/<домен>/config/*.php`** — конфигурация (объяснение каждого файла — раздел 4).

---

## 1. Создание нового сайта — 5 шагов

### Шаг 1. Скопировать эталонный каркас

Самый быстрый путь — скопировать готовый шаблон сайта со всеми конфигами:

```bash
cp -r sites/demo.localhost sites/mydomain.com    # каркас с комментариями в каждом файле
cd sites/mydomain.com && cp .env.example .env
mkdir -p storage/{cache,logs,sessions,uploads,views}
touch storage/{cache,logs,sessions,uploads,views}/.gitkeep
```

(Папка `sites/demo.localhost` в этом репозитории — полный образцовый сайт-каркас.)

### Шаг 2. Заполнить `.env`

```bash
# Секрет сайта — ОБЯЗАТЕЛЬНО сгенерировать уникальный:
php -r "echo bin2hex(random_bytes(32));"   # результат вставить в APP_SECRET=
```

Остальное можно оставить дефолтом: сайт работает **без базы данных**, без Redis, без SMTP
(`DB_ENABLED=false`, `SESSION_DRIVER=file`, `MAIL_METHOD=log`). Всё подключается по мере надобности.

### Шаг 3. Написать маршруты — `bootstrap/routes.php`

Это **главный файл вашего сайта**. Точка входа вызывает его после старта ядра:

```php
return static function (KernelInterface $kernel, RouterInterface $router): void {
    $router->get('/', fn (RequestInterface $r) => Response::html('Привет, мир!'));

    $router->get('/post/{id:\d+}', function (RequestInterface $r, int $id) use ($kernel): Response {
        return Response::html($kernel->get(ViewInterface::class)->render('post', ['id' => $id]));
    });
};
```

Правила (§17, §31):
- хендлер **возвращает** `Response` (или строку/массив — ядро обернёт), **никогда не делает `echo`**;
- параметры `{name}` и ограничители `{id:\d+}` типизируются автоматически (`int $id`);
- чужой метод на совпавшем пути → 405, несуществующий путь → 404 — ядро само.

### Шаг 4. Шаблоны — `storage/views/*.phtml`

Нативный PHP, без внешних шаблонизаторов. Данные приходят переменными, `$esc()` — экранирование:

```php
<h1><?= $esc($title) ?></h1>
```

Файл `home.phtml` рендерится как `$view->render('home', ['title' => ...])`.

### Шаг 5. Запустить

**Локально (dev):**
```bash
php -S localhost:8080 -t public public/index.php
curl -H 'Host: mydomain.com' http://localhost:8080/     # или hosts-файл + браузер
```

**Продакшен (nginx/Apache):** DOCUMENT_ROOT веб-сервера указываете на `public/`,
домен `mydomain.com` привязываете к этому же root (виртуальный хост). Папку сайта называете
по домену: `sites/mydomain.com`. Всё — движок сам определит сайт по `Host:`.

Права: веб-пользователь должен иметь запись в `sites/*/storage/` (логи, кэш, сессии).

Проверка здоровья: `GET /health` — JSON со списком взведённых модулей.
Самодиагностика из CLI: `php bin/console.php doctor --site=mydomain.com`.

---

## 2. Где конфиги привязки домена к папке? (вопрос №1)

**Они не нужны.** Привязка закодирована в структуре ФС: `sites/<домен>`. Единственные «переключатели»:

| Что | Где | Приоритет |
|---|---|---|
| Домен веб-запроса | заголовок `Host:` (веб-сервер) | 1 (web) |
| Сайт для CLI | флаг `--site=<домен>` в `bin/console.php` | 1 (CLI) |
| Дефолт для тестов/CLI без флага | `SITE_DOMAIN` в env | 2 |
| Принудительная привязка (одноимённый сервер) | `SITE_URL=` в env процесса | 0 (max) |

⚠️ Корневой `/.env` (`SITE_URL=https://demo.localhost`) задаёт режим движка и **демо-сайт для CLI**;
в веб-цикле он перекрыт виртуальным хостом и не ломает многосайтовость.

Логика разрешения: `core/SiteContext.php::detect()` — читается за минуту, комментариев достаточно.

---

## 3. Главный конфиг движка полон? (вопрос №2)

Корневой `.env` — это **только режим движка** (5 параметров):

| Переменная | Значения | Смысл |
|---|---|---|
| `CORE_DEBUG` | `true/false` | true — тексты ошибок в ответах 500. Продакшен: только false (§31) |
| `APP_ENV` | production/staging/dev | семантический режим окружения |
| `APP_TIMEZONE` | IANA TZ | часовой пояс по умолчанию (переопределяется сайтом) |
| `TRUSTED_PROXIES` | IP через запятую | кому доверять X-Forwarded-* (пусто = никому) |
| `SITE_URL` | URL | дефолтный сайт для CLI/демо (web-хост важнее) |

Все **сайтовые** настройки (сессии, кэш, БД, почта…) лежат в `sites/<домен>/.env` — см. п.4.
Разделение сознательное: движок один, сайтов много, секреты изолированы per-site.

---

## 4. Что за файлы в `sites/<домен>/config/`? (вопрос №3)

Каждый файл = секция конфига, доступна коду как `$config->get('<файл>.<ключ>')`:

| Файл | Секция | Ключевые параметры | Что делает |
|---|---|---|---|
| `core.php` | core | `debug`, `timezone` | режим ошибок и TZ сайта |
| `storage.php` | storage | `path` | jailed-root файлового хранилища (§20): весь файловый ввод-вывод сайта заперт в этой папке |
| `logger.php` | logger | `channels[]`, `dir`, `channel` | каналы логов (`app/error/security/database`), ротация по дням в `storage/logs` |
| `session.php` | session | `driver` (file/redis/**migrating**), `name`, `ttl`, `redis.*` | хранение сессий; `migrating` = безопасная миграция file→redis с read-through при падении Redis |
| `cache.php` | cache | `driver`, `dir`, `ttl` | атомарный файловый кэш |
| `database.php` | database | `enabled`, `dsn`, `user`, `pass` | PDO-подключение; `enabled=false` — сайт живёт без БД, обращение даст внятное исключение |
| `view.php` | view | `dir` | каталог шаблонов `.phtml` внутри storage |
| `security.php` | security | `csrf_ttl`, `password_algo` | CSRF-токены, хеширование паролей (секрет — `APP_SECRET` из .env) |
| `upload.php` | upload | `max_size`, `allowed_ext[]`, `allowed_mime[]`, `dir` | FileUpload: сервер проверяет реальный MIME по содержимому; исполняемое запрещено всегда |

Недостающая секция = значения по умолчанию модуля; лишняя = игнорируется. Добавляйте свои файлы —
они станут доступны как `<имя-файла>.*` без регистрации.

Переменные `.env` сайта картируются в конфиг автоматически: `SESSION_DRIVER` → `session.driver`,
`DB_DSN` → `database.dsn` и т.д. (UPPER_SNAKE_CASE → lowercase.dot.notation).

---

## 5. Структура папки сайта (эталон)

```
sites/mydomain.com/
├── .env                  # секреты сайта (НЕ коммитится)
├── .env.example          # шаблон для копирования
├── config/               # 9 PHP-секций (см. таблицу выше)
│   ├── core.php  storage.php  logger.php  session.php  cache.php
│   ├── database.php  view.php  security.php  upload.php
├── bootstrap/            # ваш PHP-код, вызывается точкой входа/CLI
│   ├── routes.php        # ★ маршруты сайта (вызывается index.php после boot)
│   ├── modules.php       # пользовательские модули (addUserModule, до boot)
│   ├── queue_handlers.php# обработчики очередей (queue:work)
│   └── schedule.php      # cron-расписание (scheduler:run)
└── storage/              # jailed-root: всё рантайм-состояние сайта
    ├── views/*.phtml     # шаблоны
    ├── logs/             # логи по дням и каналам
    ├── cache/  sessions/  uploads/  migrations/
```

### Cron (одна строка на сайт)

```cron
* * * * * php /path/to/engine/bin/console.php scheduler:run --site=mydomain.com >> /dev/null 2>&1
```

Очередь (демон или systemd-timer):

```bash
php bin/console.php queue:work --site=mydomain.com --limit=50
```

Миграции БД (файлы в `storage/migrations/NNNN_name.up.php|.down.php`):

```bash
php bin/console.php migrate --site=mydomain.com
php bin/console.php migrate:status --site=mydomain.com
```

---

## 6. Чего нельзя (Закон проверит за вас)

- `echo`/`print`/`exit` вне `Kernel::send()` — только возврат `Response` (§31);
- прямой доступ к ФС (`fopen`, `unlink`, …) в обход `FileStorageInterface` (§20);
- секреты в PHP-файлах конфигов — только `.env` (§8.1);
- второй точки входа в папке сайта (`sites/*/index.php`) — единый вход `public/index.php`;
- `eval`, `extract`, `$$var` в коде движка (§34).

Перед деплоем: `composer lex` («Конституционный суд») — покажет нарушения строк и правил.

---

## 7. Полезные команды

```bash
php bin/lex.php                                   # проверка Законом
./vendor/bin/phpunit                              # все тесты движка
php bin/console.php doctor --site=mydomain.com    # самодиагностика модулей сайта
php -S localhost:8080 -t public public/index.php  # dev-сервер (Host: <папка-сайта>)
```

Дальше: `README.md` (философия), `CONSTITUTION.md` (полный свод правил), `AI_DIRECTIVE.md`.
