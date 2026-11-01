<?php
declare(strict_types=1);

namespace Core\Module\Notification;

use Core\Contract\ConfigInterface;
use Core\Contract\KernelInterface;
use Core\Contract\LoggerInterface;
use Core\Contract\ModuleInterface;
use Core\Contract\NotificationInterface;

/**
 * Модуль Notification (P2). Конфиг секции 'notification':
 * driver (log|smtp|mail), from, smtp_*, timeout.
 */
final class NotificationModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Notification';
    }

    public function getVersion(): string
    {
        return MailNotifier::VERSION;
    }

    public function getDependencies(): array
    {
        return [ConfigInterface::class];
    }

    public function getProvides(): array
    {
        return [NotificationInterface::class];
    }

    public function boot(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $config = $container->get(ConfigInterface::class);
        assert($config instanceof ConfigInterface);

        $section = (array) ($config->get('notification') ?? []);
        $logger = null;
        try {
            $candidate = $container->get(LoggerInterface::class);
            if ($candidate instanceof LoggerInterface) {
                $logger = $candidate;
            }
        } catch (\Throwable) {
            // Logger опционален: без него driver=log просто вернет false.
        }

        $notifier = new MailNotifier($section, $logger);
        $container->protect(NotificationInterface::class, static fn (): NotificationInterface => $notifier);
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'version' => MailNotifier::VERSION];
    }
}
