<?php

namespace Tests\Unit;

use Apollo\Core\Mail\MailMessage;
use Apollo\Core\Mail\Transports\SmtpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Handshake SMTP real contra un servidor falso local: AUTH LOGIN (334 → 334 →
 * 235), DATA y QUIT (221). Regresión: los códigos intermedios 334 deben
 * aceptarse (antes se trataban como respuesta inesperada y el envío fallaba
 * en silencio por el fallback del Mailer).
 */
class SmtpTransportTest extends TestCase
{
    public function test_auth_login_and_quit_sequence(): void
    {
        $port = random_int(30000, 45000);
        $fixture = dirname(__DIR__) . '/Fixtures/fake_smtp_server.php';

        $proc = proc_open(
            [PHP_BINARY, $fixture, (string) $port],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'no se pudo lanzar el servidor SMTP falso');

        try {
            // Sondas de disponibilidad: el fixture descarta conexiones sin EHLO.
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe !== false) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(50_000);
            }
            $this->assertTrue($ready, 'el servidor SMTP falso no llegó a escuchar');

            $transport = new SmtpTransport('127.0.0.1', $port, 'usuario', 'clave', 'none', false, 5);
            $ok = $transport->send(
                MailMessage::to('destino@test.local', 'Ana')->subject('Bienvenida')->text('Hola')
            );

            $this->assertTrue($ok, 'el envío SMTP debía completar AUTH LOGIN y QUIT');

            usleep(200_000);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $this->assertSame(0, (int) $status['exitcode'], 'el fixture SMTP terminó con error');
            }
        } finally {
            foreach ($pipes ?? [] as $pipe) {
                @fclose($pipe);
            }
            @proc_terminate($proc);
        }
    }
}
