<?php

namespace Apollo\Core\Mail\Transports;

use Apollo\Core\Mail\Concerns\FromAddress;
use Apollo\Core\Mail\Contracts\Transport;
use Apollo\Core\Mail\MailMessage;

/**
 * Transporte SMTP mínimo sin dependencias externas (fsockopen + STARTTLS + AUTH
 * LOGIN). Pensado para proveedores estándar (Resend, Mailgun, SendGrid, SMTP
 * propio). Los errores se lanzan como RuntimeException; el Mailer los captura.
 */
class SmtpTransport implements Transport
{
    use FromAddress;

    public function __construct(
        private string $host = 'localhost',
        private int $port = 587,
        private string $username = '',
        private string $password = '',
        private string $encryption = 'tls', // tls | ssl | none
        private bool $verifyPeer = true,
        private int $timeout = 15,
    ) {
    }

    public function send(MailMessage $message): bool
    {
        $socket = $this->connect();

        try {
            // EHLO consume la respuesta completa (multi-línea 250-…250) en expect().
            $this->command($socket, 'EHLO ' . $this->hostname());

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP: no se pudo activar STARTTLS');
                }
                $this->command($socket, 'EHLO ' . $this->hostname());
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN');
                $this->command($socket, base64_encode($this->username));
                $this->command($socket, base64_encode($this->password));
            }

            $from = $this->fromOf($message)[0];
            $this->command($socket, "MAIL FROM:<{$from}>");
            $this->command($socket, "RCPT TO:<{$message->to}>");
            $this->command($socket, 'DATA');
            $this->writeData($socket, $message);
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT');

            return true;
        } finally {
            @fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $target = $this->host . ':' . $this->port;
        $context = stream_context_create([
            'ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer],
        ]);

        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $scheme . $target,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("SMTP: no se pudo conectar a {$target} ({$errno}: {$errstr})");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->expect($socket, [220]);
        return $socket;
    }

    /** @param resource $socket */
    private function command($socket, string $line): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $line === 'DATA' ? [354] : [250]);
    }

    /** @param resource $socket */
    private function writeData($socket, MailMessage $message): void
    {
        $body = $this->buildData($message);
        // Punto de cierre SMTP: línea con "." (y la cabecera del mensaje ya va
        // con saltos de línea normalizados CRLF).
        fwrite($socket, $body . "\r\n.\r\n");
    }

    private function buildData(MailMessage $message): string
    {
        [$from, $fromName] = $this->fromOf($message);
        $to = $message->toName ? "{$message->toName} <{$message->to}>" : $message->to;

        $headers = [
            "From: {$fromName} <{$from}>",
            "To: {$to}",
            "Subject: {$message->subject}",
            'MIME-Version: 1.0',
            'Date: ' . date(DATE_RFC2822),
        ];

        if ($message->html !== '') {
            $boundary = 'mail-' . bin2hex(random_bytes(8));
            $text = $message->text !== '' ? $message->text : strip_tags($message->html);
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
            $headers = implode("\r\n", $headers) . "\r\n\r\n";
            $headers .= "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$text}\r\n\r\n";
            $headers .= "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\n\r\n{$message->html}\r\n\r\n";
            $headers .= "--{$boundary}--";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=utf-8';
            $headers = implode("\r\n", $headers) . "\r\n\r\n" . $message->text;
        }

        // Normalización CRLF (SMTP lo exige) y escape de líneas que empiezan con ".".
        return preg_replace("/\r\n\./", "\r\n..", str_replace("\r\n", "\n", str_replace("\n", "\r\n", $headers)));
    }

    /** @param resource $socket */
    private function expect($socket, array $codes): void
    {
        $response = '';
        $start = time();

        while (true) {
            $chunk = fgets($socket, 1024);
            if ($chunk === false) {
                if (time() - $start >= $this->timeout) {
                    throw new \RuntimeException('SMTP: timeout leyendo respuesta');
                }
                continue;
            }
            $response .= $chunk;
            // Una respuesta multi-línea termina en "XXX " (espacio tras el código).
            if (strlen($chunk) >= 4 && $chunk[3] === ' ') {
                break;
            }
            if (strlen($chunk) >= 4 && $chunk[3] === '-') {
                continue;
            }
            break;
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP: respuesta inesperada: ' . trim($response));
        }
    }

    private function hostname(): string
    {
        return gethostname() ?: 'localhost';
    }
}