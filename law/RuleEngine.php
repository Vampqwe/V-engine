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
		$this->detectors = [
			'C-009' => new Detector\StrictTypesDetector(),
			'C-011' => new Detector\NewCoreInstantiationDetector(), // ← ДОБАВЛЕНО
			// Сюда будут добавляться: 'C-031' => new Detector\DirectFileLogDetector(), и т.д.
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
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    $content = file_get_contents($filePath);

                    $violations = array_merge(
                        $violations,
                        $this->checkFile($filePath, $content)
                    );
                }
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