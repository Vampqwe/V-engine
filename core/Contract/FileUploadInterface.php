<?php
declare(strict_types=1);

namespace Core\Contract;

/**
 * Контракт модуля FileUpload (Контракт §16.2, уровень P2).
 *
 * Правила Конституции:
 *  - загрузка ТОЛЬКО в jailed-root FileStorage (§20 — никакого произвольного fs);
 *  - MIME определяется по содержимому (finfo), клиентский type не доверяется;
 *  - белый список расширений/MIME + лимит размера задаются конфигом сайта;
 *  - итоговое имя генерируется сервером (никакого пользовательского пути).
 */
interface FileUploadInterface
{
    /**
     * Сохранить загруженный файл в хранилище под заданной директорией.
     *
     * @param UploadedFile $file       DTO из точки входа (tmp_name уже проверен is_uploaded_file)
     * @param string       $directory  относительная директория внутри jailed-root (напр. 'uploads/avatars')
     * @param array<string,mixed>|null $overrides опции из конфига: max_size, allowed_ext, allowed_mime
     *
     * @return string финальный относительный путь сохранённого файла (для FileStorageInterface::read)
     *
     * @throws \Core\Exception\UploadException при нарушении любого ограничения
     */
    public function store(UploadedFile $file, string $directory, ?array $overrides = null): string;

    /** Проверить файл на соответствие ограничениям БЕЗ сохранения (для валидаторов форм). */
    public function validate(UploadedFile $file, ?array $overrides = null): ValidationResult;

    /** Разбор элемента $_FILES-массива запроса в DTO. Возвращает null, если поля нет. */
    public function fromRequest(RequestInterface $request, string $field): ?UploadedFile;
}
