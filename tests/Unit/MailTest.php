<?php

namespace Tests\Unit;

use Apollo\Core\Mail\Contracts\Transport;
use Apollo\Core\Mail\Mailer;
use Apollo\Core\Mail\MailMessage;
use Apollo\Core\Mail\Template;
use Apollo\Core\Mail\Transports\LogTransport;
use PHPUnit\Framework\TestCase;

/**
 * Módulo de correo (EMAIL-01):
 * - driver log: escribe el email renderizado y devuelve éxito.
 * - el Mailer nunca lanza al caller y reintenta una vez (D1).
 * - Template::render sustituye placeholders y ignora desconocidos.
 */
class MailTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mail-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function test_log_transport_writes_rendered_email(): void
    {
        $mailer = new Mailer(new LogTransport($this->tmpDir));

        $ok = $mailer->send(
            MailMessage::to('destino@test.local', 'Ana')
                ->subject('Bienvenida')
                ->html('<h1>Hola Ana</h1><p>Contenido</p>')
                ->text('Hola Ana: Contenido')
        );

        $this->assertTrue($ok);
        $files = glob($this->tmpDir . '/*.html');
        $this->assertCount(1, $files);

        $body = (string) file_get_contents($files[0]);
        $this->assertStringContainsString('destino@test.local', $body);
        $this->assertStringContainsString('Subject: Bienvenida', $body);
        $this->assertStringContainsString('<h1>Hola Ana</h1>', $body);
        $this->assertStringContainsString('Hola Ana: Contenido', $body);
    }

    public function test_mailer_never_throws_and_retries_once(): void
    {
        $failing = new class implements Transport {
            public int $calls = 0;

            public function send(MailMessage $message): bool
            {
                $this->calls++;
                throw new \RuntimeException('smtp caído');
            }
        };

        $mailer = new Mailer($failing);
        $this->assertFalse($mailer->send(MailMessage::to('a@b.c')->subject('X')));
        $this->assertSame(2, $failing->calls); // intento inicial + 1 retry
    }

    public function test_mailer_does_not_retry_on_success(): void
    {
        $ok = new class implements Transport {
            public int $calls = 0;

            public function send(MailMessage $message): bool
            {
                $this->calls++;
                return true;
            }
        };

        $mailer = new Mailer($ok);
        $this->assertTrue($mailer->send(MailMessage::to('a@b.c')));
        $this->assertSame(1, $ok->calls);
    }

    public function test_template_render_substitutes_and_ignores_unknown(): void
    {
        $html = '<p>Hola {{nombre}}, torneo «{{torneo}}» el {{fecha}}</p>';
        $this->assertSame(
            '<p>Hola Ana, torneo «Copa» el </p>',
            Template::render($html, ['nombre' => 'Ana', 'torneo' => 'Copa'])
        );
    }

    public function test_template_render_supports_nested_keys(): void
    {
        $this->assertSame(
            'juego 3',
            Template::render('juego {{partido.numero}}', ['partido' => ['numero' => 3]])
        );
    }

    public function test_send_template_missing_returns_false(): void
    {
        $mailer = new Mailer(new LogTransport($this->tmpDir));
        $this->assertFalse($mailer->sendTemplate('no_existe', 'a@b.c'));
    }

    public function test_email_changed_template_renders_name_and_new_email(): void
    {
        $template = Template::get('email_changed');
        $this->assertNotNull($template);
        $this->assertStringContainsString('TorneoMaster', $template['subject']);

        $vars = ['name' => 'Ana', 'new_email' => 'nueva@test.local'];

        $rendered = Template::render($template['html'], $vars);
        $this->assertStringContainsString('Ana', $rendered);
        $this->assertStringContainsString('nueva@test.local', $rendered);
        $this->assertStringContainsString('si no fuiste tú, recupera tu cuenta', mb_strtolower($rendered));

        $text = Template::render($template['text'], $vars);
        $this->assertStringContainsString('nueva@test.local', $text);
        $this->assertStringContainsString('si no fuiste tú, recupera tu cuenta', mb_strtolower($text));
    }
}