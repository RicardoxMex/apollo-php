<?php
// core/Console/Command.php

namespace Apollo\Core\Console;

abstract class Command
{
    protected string $signature;
    protected string $description;
    protected array $arguments = [];
    protected array $options = [];

    abstract public function handle(): int;

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    protected function info(string $message): void
    {
        echo "\033[32m{$message}\033[0m\n";
    }

    protected function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m\n";
    }

    protected function warn(string $message): void
    {
        echo "\033[33m{$message}\033[0m\n";
    }

    protected function line(string $message = ''): void
    {
        echo $message . "\n";
    }

    protected function table(array $headers, array $rows): void
    {
        // Calcular anchos de columnas
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        // Imprimir encabezados
        $this->printTableRow($headers, $widths);
        $this->printTableSeparator($widths);

        // Imprimir filas
        foreach ($rows as $row) {
            $this->printTableRow($row, $widths);
        }
    }

    private function printTableRow(array $row, array $widths): void
    {
        echo '| ';
        foreach ($row as $i => $cell) {
            echo str_pad($cell, $widths[$i]) . ' | ';
        }
        echo "\n";
    }

    private function printTableSeparator(array $widths): void
    {
        echo '|';
        foreach ($widths as $width) {
            echo str_repeat('-', $width + 2) . '|';
        }
        echo "\n";
    }
}