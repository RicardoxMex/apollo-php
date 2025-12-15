<?php

use ApolloPHP\Core\Application;

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = [])
    {
        if ($abstract === null) {
            return Application::getInstance();
        }
        
        return Application::getInstance()->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, $default = null)
    {
        if ($key === null) {
            return app('config');
        }
        
        return app('config')->get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        
        if ($value === false) {
            return value($default);
        }
        
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }
        
        return $value;
    }
}

if (!function_exists('value')) {
    function value($value, ...$args)
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }
}

if (!function_exists('module')) {
    function module(string $name): ?\ApolloPHP\Core\Module
    {
        return app('modules')->get($name);
    }
}

if (!function_exists('route')) {
    function route(string $name, array $parameters = []): string
    {
        return app('router')->url($name, $parameters);
    }
}

if (!function_exists('now')) {
    function now($timezone = null): \Carbon\Carbon
    {
        return \Carbon\Carbon::now($timezone);
    }
}

if (!function_exists('uuid')) {
    function uuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = '', array $headers = []): void
    {
        throw new \ApolloPHP\Exceptions\HttpException($code, $message, $headers);
    }
}

if (!function_exists('response')) {
    function response($content = '', int $status = 200, array $headers = []): \ApolloPHP\Http\Response
    {
        if (is_array($content)) {
            return \ApolloPHP\Http\Response::json($content, $status, $headers);
        }
        
        return new \ApolloPHP\Http\Response($content, $status, $headers);
    }
}

if (!function_exists('json')) {
    function json($data, int $status = 200, array $headers = []): \ApolloPHP\Http\JsonResponse
    {
        return new \ApolloPHP\Http\JsonResponse($data, $status, $headers);
    }
}

if (!function_exists('data_get')) {
    function data_get($target, $key, $default = null)
    {
        if (is_null($key)) {
            return $target;
        }
        
        $key = is_array($key) ? $key : explode('.', $key);
        
        foreach ($key as $i => $segment) {
            unset($key[$i]);
            
            if (is_null($segment)) {
                return $target;
            }
            
            if ($segment === '*') {
                if (!is_array($target)) {
                    return value($default);
                }
                
                $result = [];
                
                foreach ($target as $item) {
                    $result[] = data_get($item, $key);
                }
                
                return in_array('*', $key) ? \ApolloPHP\Support\Arr::flatten($result) : $result;
            }
            
            if (\ApolloPHP\Support\Arr::accessible($target) && \ApolloPHP\Support\Arr::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }
        
        return $target;
    }
}