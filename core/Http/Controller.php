<?php
// core/Http/Controller.php

namespace Apollo\Core\Http;

use Apollo\Core\Container\Container;

abstract class Controller {
    protected Container $container;
    protected Request $request;
    protected Response $response;
    
    public function __construct(Container $container) {
        $this->container = $container;
        $this->request = $container->make('request');
        $this->response = $container->make('response');
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