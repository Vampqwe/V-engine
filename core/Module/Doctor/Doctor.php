<?php
declare(strict_types=1);

namespace Core\Module\Doctor;

use Core\Contract\HealthCheckInterface;
use Core\Exception\EngineException;

/**
 * Doctor (P3, §7.2/§8.4): агрегатор самодиагностики.
 *
 * Принцип Конституции: модуль ОТЧИТЫВАЕТСЯ сам (HealthCheckInterface),
 * Doctor только собирает отчёты — никаких reflection-«шпионажей» (§7.3)
 * и прямых обращений к внутренностям модулей.
 */
final class Doctor
{
    public const VERSION = '1.0.0';

    /** @var array<string, HealthCheckInterface> */
    private array $checks = [];

    /**
     * Зарегистрировать самоотчёт модуля. Повторная регистрация того же
     * имени заменяет предыдущую (перезапуск модуля в тестах).
     */
    public function register(HealthCheckInterface $check): void
    {
        $this->checks[$check->getHealthName()] = $check;
    }

    /** @return string[] имена зарегистрированных проверок */
    public function names(): array
    {
        return array_keys($this->checks);
    }

    /**
     * Полный отчёт: по каждой проверке + общий вердикт.
     * Исключение из healthCheck() — враг стабильности: перехватываем и
     * превращаем в fail (контракт запрещает бросать, но защищаемся).
     *
     * @return array{status: string, summary: array{ok:int,warning:int,fail:int}, checks: array<int, array>}
     */
    public function diagnose(): array
    {
        $checks = [];
        $summary = ['ok' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($this->checks as $name => $check) {
            try {
                $report = $check->healthCheck();
                if (!is_array($report) || !isset($report['status'])) {
                    $report = ['name' => $name, 'status' => HealthCheckInterface::STATUS_FAIL, 'message' => 'Malformed health report.', 'context' => []];
                }
                $report['name'] ??= $name;
            } catch (\Throwable $e) {
                $report = [
                    'name'    => $name,
                    'status'  => HealthCheckInterface::STATUS_FAIL,
                    'message' => 'Health check crashed: ' . $e->getMessage(),
                    'context' => [],
                ];
            }
            $status = $report['status'];
            if (!isset($summary[$status])) {
                $status = HealthCheckInterface::STATUS_FAIL;
                $report['status'] = $status;
            }
            $summary[$status]++;
            $checks[] = $report;
        }

        $overall = match (true) {
            $summary['fail'] > 0    => HealthCheckInterface::STATUS_FAIL,
            $summary['warning'] > 0 => HealthCheckInterface::STATUS_WARNING,
            default                 => HealthCheckInterface::STATUS_OK,
        };

        return ['status' => $overall, 'summary' => $summary, 'checks' => $checks];
    }

    /**
     * Текстовый вид для CLI (`bin/console.php doctor`).
     */
    public function report(): string
    {
        $d = $this->diagnose();
        $icon = [
            HealthCheckInterface::STATUS_OK      => '✅',
            HealthCheckInterface::STATUS_WARNING => '⚠️ ',
            HealthCheckInterface::STATUS_FAIL    => '❌',
        ];
        $lines = [];
        foreach ($d['checks'] as $c) {
            $ctx = $c['context'] !== [] ? '  ' . json_encode($c['context'], JSON_UNESCAPED_UNICODE) : '';
            $lines[] = sprintf('%s %-16s %s%s', $icon[$c['status']] ?? '?', $c['name'], $c['message'], $ctx);
        }
        $lines[] = str_repeat('-', 60);
        $lines[] = sprintf(
            'Вердикт: %s  (ok:%d warning:%d fail:%d)',
            $icon[$d['status']] ?? '?',
            $d['summary']['ok'],
            $d['summary']['warning'],
            $d['summary']['fail'],
        );
        return implode("\n", $lines);
    }

    /**
     * Быстрая проверка одного модуля (для /health-маршрута или алертов).
     */
    public function check(string $name): array
    {
        if (!isset($this->checks[$name])) {
            throw new EngineException('No health check registered for module: ' . $name);
        }
        return $this->checks[$name]->healthCheck();
    }
}
