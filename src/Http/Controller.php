<?php

namespace ApolloPHP\Http;

use ApolloPHP\Core\Container;

abstract class Controller
{
    protected Container $container;
    
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    
    protected function json($data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }
    
    protected function response($content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }
    
    protected function view(string $view, array $data = [], int $status = 200, array $headers = []): Response
    {
        if ($this->container->has('view')) {
            $content = $this->container->get('view')->render($view, $data);
            return new Response($content, $status, $headers);
        }
        
        throw new \RuntimeException('View service not registered');
    }
    
    protected function redirect(string $url, int $status = 302): Response
    {
        return (new Response())
            ->withStatus($status)
            ->withHeader('Location', $url);
    }
    
    protected function validate(array $data, array $rules, array $messages = []): array
    {
        if ($this->container->has('validator')) {
            $validator = $this->container->get('validator');
            return $validator->validate($data, $rules, $messages);
        }
        
        // Validación simple si no hay validador
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $rulesArray = explode('|', $rule);
            
            foreach ($rulesArray as $singleRule) {
                if ($singleRule === 'required' && empty($data[$field])) {
                    $errors[$field][] = "The $field field is required.";
                }
                
                if ($singleRule === 'email' && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "The $field must be a valid email address.";
                }
            }
        }
        
        if (!empty($errors)) {
            throw new \ApolloPHP\Exceptions\ValidationException($errors);
        }
        
        return $data;
    }
}