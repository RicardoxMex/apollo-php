<?php

namespace Apps\ApolloAuth\Exceptions;

/**
 * El organizador intenta publicar/iniciar un torneo sin el email verificado.
 * HTTP 403 + código de negocio EMAIL_NOT_VERIFIED (D2): el frontend lo usa
 * para ofrecer el reenvío de verificación.
 */
class EmailNotVerifiedException extends \RuntimeException
{
    public const CODE = 'EMAIL_NOT_VERIFIED';

    public function __construct(string $message = 'Verifica tu email para publicar torneos')
    {
        parent::__construct($message, 403);
    }

    /** Código de negocio estable para la UI. */
    public function businessCode(): string
    {
        return self::CODE;
    }
}