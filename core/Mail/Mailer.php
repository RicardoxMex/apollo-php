<?php

namespace Apollo\Core\Mail;

use Apollo\Core\Mail\Contracts\Transport;

/**
 * Mailer: fachada de envío con política de robustez (D1):
 * - El transporte puede fallar; el Mailer reintenta una vez y loguea, pero
 *   NUNCA lanza hacia el caller: el flujo principal no se rompe por correo.
 * - Conveniencia sendTemplate(): resuelve el registro de plantillas (subject,
 *   html, text) y rellena los {{placeholders}} con Template::render().
 */
class Mailer
{
    private int $maxAttempts;

    public function __construct(
        private Transport $transport,
        private array $config = [],
    ) {
        $this->maxAttempts = 1 + max(0, (int) ($config['retry'] ?? 1));
    }

    public function send(MailMessage $message): bool
    {
        $attempts = 0;

        while (true) {
            try {
                if ($this->transport->send($message)) {
                    return true;
                }
                throw new \RuntimeException('Transporte devolvió false sin excepción');
            } catch (\Throwable $e) {
                $attempts++;
                error_log("Mailer falló (intento {$attempts}): " . $e->getMessage());
                if ($attempts >= $this->maxAttempts) {
                    return false;
                }
            }
        }
    }

    public function sendTemplate(string $name, string $to, array $vars = [], ?string $toName = null): bool
    {
        $template = Template::get($name);
        if ($template === null) {
            error_log("Plantilla de email no encontrada: {$name}");
            return false;
        }

        return $this->send(
            MailMessage::to($to, $toName)
                ->subject(Template::render($template['subject'], $vars))
                ->html(Template::render($template['html'], $vars))
                ->text(Template::render($template['text'], $vars))
        );
    }
}