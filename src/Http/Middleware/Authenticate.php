<?php

namespace ApolloPHP\Http\Middleware;

use ApolloPHP\Http\Request;
use ApolloPHP\Http\Response;
use ApolloPHP\Exceptions\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

class Authenticate implements MiddlewareInterface
{
    protected array $guards = ['api'];
    
    public function __construct(array $guards = null)
    {
        if ($guards !== null) {
            $this->guards = $guards;
        }
    }
    
    public function handle(Request $request, callable $next)
    {
        $this->authenticate($request);
        
        return $next($request);
    }
    
    protected function authenticate(Request $request): void
    {
        // Verificar si hay un token Bearer en el header Authorization
        $token = $request->bearerToken();
        
        if (!$token) {
            throw new HttpException(401, 'Unauthenticated. No token provided.');
        }
        
        // Aquí puedes agregar la lógica de validación del token
        // Por ejemplo, validar JWT, verificar en base de datos, etc.
        if (!$this->validateToken($token)) {
            throw new HttpException(401, 'Unauthenticated. Invalid token.');
        }
        
        // Si llegamos aquí, el token es válido
        // Puedes agregar el usuario autenticado al request si es necesario
        // $request->setAttribute('user', $user);
    }
    
    protected function validateToken(string $token): bool
    {
        // Implementación básica - deberías reemplazar esto con tu lógica real
        // Por ejemplo, validar JWT, verificar en base de datos, etc.
        
        // Por ahora, solo verificamos que el token no esté vacío
        // En una implementación real, aquí validarías el JWT o consultarías la base de datos
        return !empty($token) && strlen($token) > 10;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Convertir PSR-7 request a nuestro Request si es necesario
        if (!$request instanceof Request) {
            // Si necesitas convertir, puedes crear un método para esto
            // Por ahora, asumimos que funciona con PSR-7
        }
        
        $this->authenticate($request);
        
        return $handler->handle($request);
    }
}