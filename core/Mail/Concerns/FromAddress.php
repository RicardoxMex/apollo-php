<?php

namespace Apollo\Core\Mail\Concerns;

use Apollo\Core\Mail\MailMessage;

/**
 * Resolución del remitente por defecto. En runtime normal sale de
 * config('mail.from'); si el contenedor no está disponible (tests unitarios
 * sin bootstrap) cae a los valores por defecto sin lanzar.
 */
trait FromAddress
{
    private function fromOf(MailMessage $message): array
    {
        if ($message->fromAddress !== null) {
            return [$message->fromAddress, $message->fromName ?? 'TorneoMaster'];
        }

        $from = ['address' => 'no-reply@torneomaster.app', 'name' => 'TorneoMaster'];
        try {
            $configured = config('mail.from', []);
            if (is_array($configured)) {
                $from = $configured + $from;
            }
        } catch (\Throwable) {
            // Sin contenedor: se mantienen los valores por defecto.
        }

        return [$from['address'] ?? 'no-reply@torneomaster.app', $from['name'] ?? 'TorneoMaster'];
    }
}