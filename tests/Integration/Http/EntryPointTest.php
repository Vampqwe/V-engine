<?php
declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;

/**
 * ШАГ 4.5: живой smoke-тест единой точки входа public/index.php.
 * закрывает разрыв «тесты ↔ продакшен»: гоняем реальный PHP built-in server
 * и проверяем полный цикл boot -> routes -> run -> send (§16.9, §31).
 */
final class EntryPointTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 8199;

    /** @var resource|null */
    private static $server = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        $cmd = sprintf(
            'php -S %s:%d -t %s/public > %s 2>&1 & echo $!',
            self::HOST,
            self::PORT,
            escapeshellarg($root),
            escapeshellarg(sys_get_temp_dir() . '/lex-entrypoint-test-server.log'),
        );
        $pid = (int) trim((string) shell_exec($cmd));
        self::$server = $pid > 0 ? $pid : null;

        // Ждём готовности сервера (макс. 5 секунд).
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(100_000);
        }
        self::fail('Built-in server не поднялся на ' . self::HOST . ':' . self::PORT);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_int(self::$server)) {
            exec('kill ' . self::$server . ' 2>/dev/null');
        }
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function get(string $path): array
    {
        // cURL-расширение может отсутствовать в CI — используем streams.
        $url = 'http://' . self::HOST . ':' . self::PORT . $path;
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,          // тело 4xx/5xx тоже читаем
            'timeout'       => 10,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $code = (int) $m[1];
                break;
            }
        }
        return [$code, $body === false ? '' : $body];
    }

    public function testHomeRendersViaViewModule(): void
    {
        [$code, $body] = $this->get('/');
        self::assertSame(200, $code, 'GET / должен отдавать 200 через ядро');
        self::assertStringContainsString('LEX Engine', $body);
        self::assertStringContainsString('demo.localhost', $body, 'SiteContext должен подставить сайт');
    }

    public function testHealthReportsAllBootedModules(): void
    {
        [$code, $body] = $this->get('/health');
        self::assertSame(200, $code);
        $json = json_decode($body, true);
        self::assertIsArray($json, '/health обязан возвращать валидный JSON');
        self::assertSame('ok', $json['status'] ?? null);
        $names = array_column($json['modules'] ?? [], 'name');
        foreach (['Config', 'FileStorage', 'Logger', 'Session', 'Security', 'Database', 'View', 'Cache'] as $required) {
            self::assertContains($required, $names, "Модуль {$required} должен быть в реестре");
        }
    }

    public function testUnknownPathReturns404NotCrash(): void
    {
        [$code, $body] = $this->get('/definitely-not-a-route');
        self::assertSame(404, $code);
        self::assertStringNotContainsString('Exception', $body, '§31: сырые трассировки наружу не выпускаются');
    }

    public function testTraversalAttemptIsRejected(): void
    {
        // FileStorage jailed-root + нормализация пути роутера: никаких 200 с чужим содержимым.
        [$code, $body] = $this->get('/..%2f..%2f.env');
        self::assertContains($code, [400, 404], 'traversal-запрос обязан быть отклонён');
        self::assertStringNotContainsString('APP_SECRET', $body);
    }
}
