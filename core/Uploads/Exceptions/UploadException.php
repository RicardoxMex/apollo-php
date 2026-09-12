<?php
// core/Uploads/Exceptions/UploadException.php

namespace Apollo\Core\Uploads\Exceptions;

use RuntimeException;

/**
 * Error de negocio del módulo Uploads (tipo no permitido, tamaño excedido,
 * entrada inválida...). Los controladores lo traducen a respuestas 4xx.
 */
class UploadException extends RuntimeException
{
}