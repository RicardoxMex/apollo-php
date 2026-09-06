# Sistema de Middlewares - Apollo PHP

## 🎯 Middlewares Implementados

### 1. **AuthMiddleware** - Autenticación JWT real
- **Propósito**: Validar tokens JWT (signature + expiración + sesión activa)
- **Ubicación**: `apps/ApolloAuth/Middleware/AuthMiddleware.php`
- **Funcionalidad**:
  - Valida header `Authorization: Bearer {token}` contra `AuthService`
  - Inyecta el usuario autenticado en `$request->user()` y en `$request->attributes['user']`
  - Devuelve `401` cuando falta el token, es inválido o está expirado
  - Sin secreto JWT configurado (`JWT_SECRET_KEY`) el acceso se rechaza (401)

> **Nota de seguridad:** la versión anterior de este middleware usaba tokens demo hardcodeados
> (`test-token-123`, `user-token-456`, `demo-token-789`) y fue eliminada. La autenticación es
> JWT real: los tokens se obtienen vía `POST /api/auth/login` y requieren una sesión activa
> en la tabla `user_sessions`.

### 2. **RoleMiddleware / PermissionMiddleware** - Módulo de acceso del core
- **Propósito**: gates de roles y permisos sobre el usuario autenticado (`hasAnyRole` / `hasAnyPermission`)
- **Ubicación**: `core/Auth/Middleware/` (`Apollo\Core\Auth\Middleware\RoleMiddleware`, `PermissionMiddleware`)
- **Activable por configuración**: `config('auth.access.enabled')` (env `AUTH_ACCESS_ENABLED`).
  Si está desactivado, ningún gate se registra (tablas `roles`/`permissions` y pivots no requeridas).
  El modelo de rol se resuelve desde `config('auth.access.role_model')` y el de permiso desde
  `config('auth.access.permission_model')` (defaults: core). Los permisos viven en la tabla
  `permissions` y se enlazan por la pivot `role_permissions` (ya no en JSON dentro del rol).
- **Alias registrados** (en `core/Providers/AppServiceProvider`, gated):
  - `role.admin`: Solo administradores
  - `role.user`: Usuarios y administradores
- Para permisos, registra alias con listas propias:
  `$container->bind('permission.moderate', fn($app) => new PermissionMiddleware(['users.view']))`

### 3. **LoggingMiddleware** - Registro de Actividad
- **Propósito**: Registrar requests y responses
- **Ubicación**: `apps/Users/Middleware/LoggingMiddleware.php`
- **Funcionalidad**:
  - Log de entrada con método, path, IP y User-Agent
  - Log de salida con status code y tiempo de respuesta

### 4. **CorsMiddleware** - Cross-Origin Resource Sharing
- **Propósito**: Manejar requests CORS
- **Ubicación**: `apps/Users/Middleware/CorsMiddleware.php`
- **Funcionalidad**:
  - Responde a requests OPTIONS (preflight)
  - Agrega headers CORS a todas las responses

## 🧪 Ejemplos de Uso

### Rutas Públicas (sin middleware)
```bash
# Listar usuarios
curl http://localhost/api/users

# Obtener usuario específico
curl http://localhost/api/users/123
```

### Rutas con Logging
```bash
# Ruta de prueba con logging
curl http://localhost/api/users/test
```

### Rutas Protegidas (requieren autenticación JWT)
```bash
# Sin token (401 Unauthorized)
curl http://localhost/api/users/profile

# Con token inválido (401 Unauthorized)
curl -H "Authorization: Bearer invalid-token" http://localhost/api/users/profile

# Obtener token real (requiere usuario con sesión y configuración JWT)
curl -X POST -H "Content-Type: application/json" \
     -d '{"email":"tucorreo@ejemplo.com","password":"tu-clave"}' \
     http://localhost/api/auth/login
```

### Rutas con Control de Roles
```bash
# Eliminar usuario (requiere rol admin)
curl -X DELETE -H "Authorization: Bearer {token-admin}" http://localhost/api/users/123

# Estadísticas (solo admin)
curl -H "Authorization: Bearer {token-admin}" http://localhost/api/users/stats
```

### Rutas con Múltiples Middlewares
```bash
# Demo con CORS, Logging y Auth
curl -H "Authorization: Bearer {token}" \
     -H "Origin: https://example.com" \
     -H "User-Agent: Test Client 1.0" \
     http://localhost/api/users/demo
```

## 📋 Resultados Esperados

### ✅ Casos Exitosos
- **Ruta pública**: 200 OK
- **Logging**: Registra correctamente entrada y salida
- **Auth válido** (JWT + sesión activa): 200 OK con datos del usuario
- **Admin con rol**: 200 OK
- **Múltiples middlewares**: 200 OK con headers CORS

### ❌ Casos de Error (esperados)
- **Sin token**: 401 Unauthorized
- **Token inválido o expirado**: 401 Unauthorized
- **Sin secreto JWT configurado**: 401 Unauthorized
- **Usuario sin permisos**: 403 Forbidden

## 🔧 Configuración de Middlewares

### Registro en ServiceProviders
```php
// El contrato (auth + roles) vive en el core (módulo de acceso, gated por config)
// core/Providers/AppServiceProvider.php (si config('auth.access.enabled'))
$this->container->bind('role.admin', fn($app) => new \Apollo\Core\Auth\Middleware\RoleMiddleware(['admin']));
$this->container->bind('role.user', fn($app) => new \Apollo\Core\Auth\Middleware\RoleMiddleware(['user', 'admin']));

// La autenticación JWT vive en apps/ApolloAuth/ApolloAuthServiceProvider.php
$this->container->bind('auth', AuthMiddleware::class);

// Los middlewares propios de la app Users (logging/cors) viven en
// apps/Users/Providers/UsersServiceProvider.php
$this->container->bind('logging', fn($container) => new LoggingMiddleware());
$this->container->bind('cors', fn($container) => new CorsMiddleware());
```

### Uso en Rutas
```php
// Middleware individual
$router->get('/test', $callback)->middleware(['logging']);

// Grupo con middleware
$router->group(['middleware' => ['auth']], function ($router) { ... });

// Grupo con roles
$router->group(['middleware' => ['auth', 'role.admin']], function ($router) { ... });
```

## 🧪 Verificación Automatizada

```bash
# Suite de tests (sin DB): cubre 401/404/200, rutas protegidas y rol admin
composer test

# Self-check del framework
php apollo test
```