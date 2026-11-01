<?php
declare(strict_types=1);

namespace Law;

use Law\Contract\DetectorInterface;
use Law\ValueObject\Violation;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RuleEngine
{
    private array $manifest;
    private array $detectors = [];

    public function __construct(string $manifestPath)
    {
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException("Manifest not found: {$manifestPath}");
        }
        $this->manifest = require $manifestPath;
        $this->registerDetectors();
    }

    private function registerDetectors(): void
    {
        // Реестр детекторов Конституционного Суда. Каждый детектор регистрируется
        // под своим ruleId; манифест (law/manifest.php) остаётся источником метаданных.
        $this->detectors = [
            'C-001' => new Detector\EmergencyBunkerDetector(),
            'C-009' => new Detector\StrictTypesDetector(),
            'C-011' => new Detector\NewCoreInstantiationDetector(),
            'C-016' => new Detector\ContainerGetLiteralDetector(),
            'C-020' => new Detector\DirectFsAccessDetector(),
            'C-031' => new Detector\DirectFileLogDetector(),
            'C-040' => new Detector\UnsafeOutputDetector(),
        ];
    }

    /**
     * Запускает проверку всей кодовой базы.
     * @param string $rootPath Корневая папка проекта (ENGINE_BASE_PATH)
     * @return array Массив объектов Violation
     */
    public function run(string $rootPath): array
    {
        $violations = [];
        // §7: пользовательское пространство сайтов тоже под надзором Закона
        $directoriesToScan = ['core', 'public', 'config', 'law', 'sites'];

        foreach ($directoriesToScan as $dir) {
            $fullDir = $rootPath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            // Runtime-артефакты сайтов (логи, сессии, кэш, опубликованные
            // шаблоны) — порождения ядра, не исходный код: под надзором Закона
            // только авторские файлы сайтов (config/bootstrap/storage/migrations).
            $rp = str_replace("\\", '/', $file->getPathname());
            if (preg_match('#sites/[^/]+/storage/(logs|sessions|cache|queue|uploads|views)/#', $rp)) {
                continue;
            }
            $ext = $file->getExtension();
            // .php — все правила; .phtml — правила для шаблонов (C-040)
            if ($ext !== 'php' && $ext !== 'phtml') continue;

            $filePath = $file->getRealPath();
            $content = file_get_contents($filePath);

            $violations = array_merge(
                $violations,
                $this->checkFile($filePath, $content)
            );
        }
        }

        return $violations;
    }

    private function checkFile(string $filePath, string $content): array
    {
        $fileViolations = [];

        foreach ($this->manifest['rules'] as $ruleId => $ruleConfig) {
            if (isset($this->detectors[$ruleId])) {
                $detector = $this->detectors[$ruleId];
                $violation = $detector->check($filePath, $content, $ruleConfig);
                
                if ($violation !== null) {
                    $fileViolations[] = $violation;
                }
            }
        }

        return $fileViolations;
    }
}