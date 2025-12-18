# ApolloAuth - Sistema de Autenticación Modular

## Descripción General

ApolloAuth es una aplicación modular completa para el framework Apollo que proporciona:

- Autenticación JWT
- Sistema de roles y permisos
- Gestión de sesiones
- Middleware de protección
- Acceso global desde cualquier módulo

## Estructura de Tablas

### 1. users
- Información básica del usuario
- Estados: active, inactive, suspended
- Soft deletes habilitado

### 2. roles
- Roles del sistema con permisos
- Roles del sistema no editables
- Permisos en formato JSON

### 3. user_roles
- Relación many-to-many entre usuarios y roles
- Auditoría de asignación

### 4. user_sessions
- Gestión de sesiones JWT
- Información del dispositivo
- Control de revocación

### 5. password_resets
- Tokens para reseteo de contraseñas
- Control de expiración y uso

### 6. rate_limits
- Control de límites de intentos
- Por IP, usuario, tipo de acción

## Uso Básico

### Autenticación

```php
use Apps\ApolloAuth\Facades\Auth;

// Login
$result = Auth::attempt([
    'email' => 'user@example.com',
    'password' => 'password'
]);

// Verificar autenticación
if (Auth::check()) {
    $user = Auth::user();
}

// Logout
Auth::logout();

// Logout de todos los dispositivos
Auth::logoutFromAllDevices();
```

### Roles y Permisos

```php
// Verificar rol
if (Auth::hasRole('admin')) {
    // Usuario es admin
}

// Verificar permiso
if (Auth::hasPermission('users.create')) {
    // Usuario puede crear usuarios
}

// Asignar rol
$user->assignRole('moderator');

// Remover rol
$user->removeRole('user');
```

### Middleware

```php
// En rutas
$router->group(['middleware' => 'auth'], function ($router) {
    $router->get('/profile', 'UserController@profile');
});

// Con roles
$router->group(['middleware' => ['auth', 'role:admin']], function ($router) {
    $router->get('/admin', 'AdminController@index');
});

// Con permisos
$router->group(['middleware' => ['auth', 'permission:users.manage']], function ($router) {
    $router->get('/users', 'UserController@index');
});
```

### Helpers Globales

```php
// Obtener usuario actual
$user = user();

// ID del usuario
$userId = user_id();

// Verificar autenticación
if (is_authenticated()) {
    // Usuario autenticado
}

// Verificar rol
if (has_role('admin')) {
    // Es admin
}

// Verificar permiso
if (has_permission('users.create')) {
    // Tiene permiso
}
```

## API Endpoints

### Autenticación

```
POST /api/auth/login
POST /api/auth/register
GET  /api/auth/profile      [auth]
POST /api/auth/logout       [auth]
POST /api/auth/logout-all   [auth]
POST /api/auth/refresh      [auth]
GET  /api/auth/sessions     [auth]
```

### Administración

```
GET    /api/auth/admin/users           [auth, role:admin]
GET    /api/auth/admin/users/{id}      [auth, role:admin]
PUT    /api/auth/admin/users/{id}      [auth, role:admin]
GET    /api/auth/admin/roles           [auth, role:admin]
POST   /api/auth/admin/users/{id}/roles     [auth, role:admin]
DELETE /api/auth/admin/users/{id}/roles/{role} [auth, role:admin]
```

## Configuración

### Variables de Entorno

```env
JWT_SECRET_KEY=your_super_secure_secret_key_32_chars_minimum
JWT_ALGORITHM=HS256
JWT_ISSUER=apollo-api.local
JWT_AUDIENCE=apollo-client
JWT_EXPIRY=3600

RATE_LIMIT_MAX_ATTEMPTS=5
RATE_LIMIT_WINDOW=900
```

### Configuración de Auth

```php
// config/auth.php
return [
    'jwt' => [
        'secret_key' => env('JWT_SECRET_KEY'),
        'expiry' => 3600, // 1 hora
    ],
    'rate_limit' => [
        'max_attempts' => 5,
        'window' => 900, // 15 minutos
    ]
];
```

## Instalación y Setup

1. **Registrar la app en config/apps.php:**
```php
'registered' => [
    'ApolloAuth',
    // otras apps...
],
```

2. **Ejecutar migraciones:**
```bash
php setup_database.php
```

3. **Ejecutar seeders:**
```bash
php run_seeders.php
```

4. **Credenciales por defecto:**
- Email: admin@apollo.local
- Password: admin123

## Roles por Defecto

- **admin**: Acceso completo (*)
- **moderator**: Moderación de contenido
- **user**: Usuario regular
- **guest**: Acceso limitado

## Seguridad

- Passwords hasheados con PASSWORD_DEFAULT
- Tokens JWT con expiración
- Rate limiting por IP
- Gestión de sesiones con revocación
- Middleware de protección
- Validación de tokens en cada request

## Extensión

Para agregar nuevos permisos o roles:

```php
// Crear nuevo rol
Role::create([
    'name' => 'editor',
    'display_name' => 'Editor',
    'permissions' => ['content.create', 'content.edit']
]);

// Asignar a usuario
$user->assignRole('editor');
```

## Acceso Global

El sistema está disponible globalmente en toda la aplicación:

```php
use Apps\ApolloAuth\Facades\Auth;

// Desde cualquier controlador
class ProductController {
    public function index() {
        if (!Auth::hasPermission('products.view')) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        
        $user = Auth::user();
        // ...
    }
}

// Usando helpers globales
if (is_authenticated()) {
    $user = user();
    $userId = user_id();
}

if (has_role('admin')) {
    // Es admin
}

// Desde middleware personalizado
class CustomMiddleware {
    public function handle($request, $next) {
        if (is_guest()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
```

## Estructura de la App

```
apps/ApolloAuth/
├── app.json                    # Configuración de la app
├── ApolloAuthServiceProvider.php
├── Controllers/
│   ├── AuthController.php      # Login, register, profile
│   └── AdminController.php     # Gestión de usuarios y roles
├── Models/
│   ├── User.php
│   ├── Role.php
│   └── UserSession.php
├── Services/
│   ├── AuthService.php         # Lógica de autenticación
│   └── JWTManager.php          # Gestión de tokens JWT
├── Middleware/
│   ├── AuthMiddleware.php
│   ├── RoleMiddleware.php
│   └── PermissionMiddleware.php
├── Facades/
│   └── Auth.php                # Facade global
├── Traits/
│   └── HasRoles.php
├── Exceptions/
│   ├── AuthenticationException.php
│   ├── InvalidCredentialsException.php
│   └── UserNotActiveException.php
├── Routes/
│   ├── auth.php                # Rutas de autenticación
│   └── admin.php               # Rutas de administración
└── helpers.php                 # Funciones helper globales
```