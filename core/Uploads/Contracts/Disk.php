<?php
// core/Uploads/Contracts/Disk.php

namespace Apollo\Core\Uploads\Contracts;

/**
 * Contrato de almacenamiento para el módulo Uploads.
 *
 * V1 implementa solo LocalDisk; drivers futuros (S3, GCS...) implementan
 * este contrato sin tocar el manager.
 */
interface Disk
{
    /**
     * Escribir contenido en un path relativo.
     */
    public function put(string $path, string $content): bool;

    /**
     * Mover/copiar un archivo del filesystem al path relativo del disco.
     */
    public function putFile(string $sourcePath, string $path): bool;

    /**
     * Contenido del archivo, o null si no existe.
     */
    public function get(string $path): ?string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    /**
     * Path absoluto resuelto y validado (null si el path es inseguro).
     */
    public function path(string $path): ?string;

    /**
     * URL pública del archivo.
     */
    public function url(string $path): string;

    public function size(string $path): int;

    public function mime(string $path): string;

    public function root(): string;
}