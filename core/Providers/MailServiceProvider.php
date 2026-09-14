<?php

namespace Apollo\Core\Providers;

use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Mail\Mailer;
use Apollo\Core\Mail\Transports\LogTransport;
use Apollo\Core\Mail\Transports\SmtpTransport;

/**
 * Módulo de correo: bindings inertes hasta que se usan (mismo patrón que
 * Realtime/Uploads). Transporte por config('mail.driver'): 'smtp' en
 * producción, 'log' en dev/test.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Mailer::class, function ($app) {
            $config = config('mail', []);

            $transport = match ($config['driver'] ?? 'log') {
                'smtp' => new SmtpTransport(
                    (string) ($config['smtp']['host'] ?? 'localhost'),
                    (int) ($config['smtp']['port'] ?? 587),
                    (string) ($config['smtp']['username'] ?? ''),
                    (string) ($config['smtp']['password'] ?? ''),
                    (string) ($config['smtp']['encryption'] ?? 'tls'),
                    (bool) ($config['smtp']['verify_peer'] ?? true),
                    (int) ($config['smtp']['timeout'] ?? 15),
                ),
                default => new LogTransport((string) ($config['log_path'] ?? 'runtime/logs/mail')),
            };

            return new Mailer($transport, $config);
        });

        // Alias 'mailer' → Mailer (para helpers/facades)
        $this->container->alias(Mailer::class, 'mailer');
    }
}