<?php
// core/Http/Controller.php

namespace Apollo\Core\Http;

use Apollo\Core\Container\Container;

abstract class Controller {
    protected Container $container;
    protected ?Request $request = null;
    
    public function __construct(Container $container) {
        $this->container = $container;
        
        // Intentar resolver request del container
        try {
            $this->request = $container->make('request');
        } catch (\Throwable $e) {
            // Request no disponible, se manejará en los métodos que lo necesiten
        }
    }
    
    protected function json($data, int $status = 200, array $headers = []) {
        return Response::json($data, $status, $headers);
    }
    
    protected function view(string $view, array $data = [], int $status = 200) {
        // Implementar luego si necesitas vistas
        return Response::html('View not implemented', $status);
    }
    
    protected function redirect(string $url, int $status = 302) {
        return Response::redirect($url, $status);
    }
}