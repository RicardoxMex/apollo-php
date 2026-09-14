<?php

namespace Apollo\Core\Mail;

/**
 * Mensaje de correo inmutável por construcción: destinatario, asunto y cuerpos
 * (HTML + texto plano). El remitente se resuelve desde config('mail.from') en el
 * transporte salvo que se especifique uno explícito.
 */
class MailMessage
{
    public function __construct(
        public string $to,
        public ?string $toName = null,
        public string $subject = '',
        public string $html = '',
        public string $text = '',
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {
    }

    public static function to(string $email, ?string $name = null): static
    {
        return new static($email, $name);
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function html(string $html): static
    {
        $this->html = $html;
        return $this;
    }

    public function text(string $text): static
    {
        $this->text = $text;
        return $this;
    }

    public function from(string $address, ?string $name = null): static
    {
        $this->fromAddress = $address;
        $this->fromName = $name;
        return $this;
    }
}