<?php

namespace Apollo\Core\Mail\Transports;

use Apollo\Core\Mail\Concerns\FromAddress;
use Apollo\Core\Mail\Contracts\Transport;
use Apollo\Core\Mail\MailMessage;

/**
 * Transporte de desarrollo/tests: escribe el email renderizado (cabeceras +
 * multipart/alternative) en un directorio de logs y devuelve éxito.
 * Nunca falla salvo que el directorio no sea escribible.
 */
class LogTransport implements Transport
{
    use FromAddress;

    public function __construct(private string $path = 'runtime/logs/mail')
    {
    }

    public function send(MailMessage $message): bool
    {
        $dir = rtrim($this->path, '/\\');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio de correo: {$dir}");
        }

        $stamp = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $message->subject)) ?: 'email';
        $slug = substr($slug, 0, 40);
        $file = $dir . DIRECTORY_SEPARATOR . "{$stamp}-{$slug}.html";

        if (@file_put_contents($file, $this->render($message)) === false) {
            throw new \RuntimeException("No se pudo escribir el email en {$file}");
        }

        return true;
    }

    private function render(MailMessage $message): string
    {
        [$from, $fromName] = $this->fromOf($message);
        $to = $message->toName ? "{$message->toName} <{$message->to}>" : $message->to;

        $header = "From: {$fromName} <{$from}>\nTo: {$to}\nSubject: {$message->subject}\n";

        if ($message->html === '') {
            return $header . "\n" . $message->text;
        }

        $text = $message->text !== '' ? $message->text : strip_tags($message->html);
        $boundary = 'mail-' . bin2hex(random_bytes(8));

        return $header
            . "MIME-Version: 1.0\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\n\n"
            . "--{$boundary}\nContent-Type: text/plain; charset=utf-8\n\n{$text}\n\n"
            . "--{$boundary}\nContent-Type: text/html; charset=utf-8\n\n{$message->html}\n\n"
            . "--{$boundary}--\n";
    }
}