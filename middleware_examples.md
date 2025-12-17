# Sistema de Middlewares - Apollo PHP

## 🎯 Middlewares Implementados

### 1. **Authenticate** - Middleware de Autenticación
- **Propósito**: Verificar tokens de autorización
- **Ubicación**: `apps/users/Middleware/Authenticate.php`
- **Funcionalidad**:
  - Valida header `Authorization: Bearer {token}`
  - Inyecta información del usuario en `$request->attributes['user']`
  - Tokens válidos de ejemplo:
    - `test-token-123` (admin)
    - `user-token-456` (user)
    - `demo-token-789` (demo)

### 2. **RoleMiddleware** - Control de Roles
- **Propósito**: Verificar permisos basados en roles
- **Ubicación**: `apps/users/Middleware/RoleMiddleware.php`
- **Variantes registradas**:
  - `role.admin`: Solo administradores
  - `role.user`: Usuarios y administradores

### 3. **LoggingMiddleware** - Registro de Actividad
- **Propósito**: Registrar requests y responses
- **Ubicación**: `apps/users/Middleware/LoggingMiddleware.php`
- **Funcionalidad**:
  - Log de entrada con método, path, IP y User-Agent
  - Log de salida con status code y tiempo de respuesta

### 4. **CorsMiddleware** - Cross-Origin Resource Sharing
- **Propósito**: Manejar requests CORS
- **Ubicación**: `apps/users/Middleware/CorsMiddleware.php`
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

### Rutas Protegidas (requieren autenticación)
```bash
# Sin token (401 Unauthorized)
curl http://localhost/api/users/profile

# Con token inválido (401 Unauthorized)
curl -H "Authorization: Bearer invalid-token" http://localhost/api/users/profile

# Con token válido (200 OK)
curl -H "Authorization: Bearer test-token-123" http://localhost/api/users/profile

# Crear usuario (autenticado)
curl -X POST -H "Authorization: Bearer user-token-456" http://localhost/api/users
```

### Rutas con Control de Roles
```bash
# Usuario normal intentando eliminar (403 Forbidden)
curl -X DELETE -H "Authorization: Bearer user-token-456" http://localhost/api/users/123

# Administrador eliminando (200 OK)
curl -X DELETE -H "Authorization: Bearer test-token-123" http://localhost/api/users/123

# Estadísticas (solo admin)
curl -H "Authorization: Bearer test-token-123" http://localhost/api/users/stats
```

### Rutas con Múltiples Middlewares
```bash
# Demo con CORS, Logging y Auth
curl -H "Authorization: Bearer demo-token-789" \
     -H "Origin: https://example.com" \
     -H "User-Agent: Test Client 1.0" \
     http://localhost/api/users/demo
```

## 📋 Resultados de Pruebas

### ✅ Casos Exitosos
- **Ruta pública**: 200 OK
- **Logging**: Registra correctamente entrada y salida
- **Auth válido**: 200 OK con datos del usuario
- **Admin eliminando**: 200 OK con confirmación
- **Múltiples middlewares**: 200 OK con headers CORS

### ❌ Casos de Error (esperados)
- **Sin token**: 401 Unauthorized
- **Token inválido**: 401 Unauthorized  
- **Usuario sin permisos**: 403 Forbidden

## 🔧 Configuración de Middlewares

### Registro en ServiceProvider
```php
// apps/users/UsersServiceProvider.php
$this->container->bind('auth', fn($container) => new Authenticate());
$this->container->bind('role.admin', fn($container) => new RoleMiddleware(['admin']));
$this->container->bind('logging', fn($container) => new LoggingMiddleware());
$this->container->bind('cors', fn($container) => new CorsMiddleware());
```

### Uso en Rutas
```php
// Middleware individual
$router->get('/test', $callback)->middleware(['logging']);

// Grupo con middleware
$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/profile', $callback);
});

// Múltiples middlewares
$router->get('/demo', $callback)->middleware(['cors', 'logging', 'auth']);
```

## 🏗️ Arquitectura del Pipeline

1. **Request** entra al sistema
2. **Kernel** ejecuta middleware global
3. **Router** encuentra la ruta
4. **Pipeline** ejecuta middleware de ruta en orden
5. **Action** se ejecuta (controller o closure)
6. **Response** pasa por middleware en orden inverso
7. **Response** se envía al cliente

## 🎉 Características Destacadas

- ✅ **Pipeline robusto** con manejo de errores
- ✅ **Middleware anidado** en grupos
- ✅ **Inyección de dependencias** automática
- ✅ **Helpers globales** (`app()`, `request()`, `response()`)
- ✅ **Logging detallado** con timestamps y métricas
- ✅ **CORS completo** con preflight support
- ✅ **Autenticación flexible** con múltiples tokens
- ✅ **Control de roles** granular