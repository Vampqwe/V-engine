<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт HTTP-запроса (ШАГ 3). Суперглобалы $_GET/$_POST запрещены (§22.3),
 * поэтому ядро инкапсулирует входной запрос в объект и передаёт его
 * middleware и обработчикам маршрутов.
 */
interface RequestInterface
{
    public function getMethod(): string;

    /** Путь без query-строки, нормализованный: leading '/', без trailing '/'. */
    public function getPath(): string;

    public function getQuery(string $key, mixed $default = null): mixed;

    public function getPost(string $key, mixed $default = null): mixed;

    /** Заголовок в нижнем регистре; null если отсутствует. */
    public function getHeader(string $name): ?string;

    /** @return array<string, string> Все заголовки (ключи в нижнем регистре). */
    public function getHeaders(): array;

    /** Атрибуты, проставленные middleware/pipeline (не из ввода!). */
    public function withAttribute(string $name, mixed $value): self;

    public function getAttribute(string $name, mixed $default = null): mixed;

    /** @return array<string, mixed> Параметры маршрута из плейсхолдеров {id}. */
    public function getRouteParams(): array;

    /** DTO загруженного файла по имени поля; null если поля нет (FileUpload P2). */
    public function getUploadedFile(string $field): ?UploadedFile;

    /** @return array<string, UploadedFile> Все файлы запроса (ключ — имя поля или field[i]). */
    public function getUploadedFiles(): array;
}
