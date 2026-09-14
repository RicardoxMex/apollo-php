<?php

namespace Apollo\Core\Mail\Contracts;

use Apollo\Core\Mail\MailMessage;

/**
 * Transporte de correo. Devuelve true en éxito; lanza Throwable si el envío
 * falla (el Mailer lo captura: reintento acotado + log, nunca rompe la petición).
 */
interface Transport
{
    public function send(MailMessage $message): bool;
}