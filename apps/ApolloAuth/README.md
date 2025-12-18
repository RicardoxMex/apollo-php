# ApolloAuth

Sistema de autenticación modular completo para Apollo Framework.

## Características

- ✅ Autenticación JWT con gestión de sesiones
- ✅ Sistema de roles y permisos flexible
- ✅ Middleware de protección (auth, role, permission)
- ✅ Rate limiting por IP/usuario
- ✅ Gestión de múltiples sesiones por usuario
- ✅ Logout de dispositivos específicos o todos
- ✅ Refresh de tokens
- ✅ API REST completa
- ✅ Helpers globales
- ✅ Facade Auth para acceso fácil

## Instalación

1. La app debe estar registrada en `config/apps.php`:

```php
'registered' => [
    'ApolloAuth',
    // ...
],
```

2. Ejecutar migraciones para crear las tablas:

```bash
php setup_database.php
```

3. Ejecutar seeders para crear roles y usuario admin:

```bash
php run_seeders.php
```

## Uso Básico

### Autenticación

```php
use Apps\ApolloAuth\Facades\Auth;

// Login
$result = Auth::attempt([
    'email' => 'user@example.com',
    'password' => 'password'
], $remember = false);

// Verificar autenticación
if (Auth::check()) {
    $user = Auth::user();
    echo "Hola, " . $user->username;
}

// Logout
Auth::logout();
```

### Helpers Globales

```php
// Usuario actual
$user = user();
$userId = user_id();

// Verificaciones
if (is_authenticated()) { }
if (is_guest()) { }
if (has_role('admin')) { }
if (has_permission('users.create')) { }
if (is_admin()) { }
```

### Middleware en Rutas

```php
// Requiere autenticación
$router->group(['middleware' => 'auth'], function ($router) {
    $router->get('/profile', 'UserController@profile');
});

// Requiere rol específico
$router->group(['middleware' => ['auth', 'role:admin']], function ($router) {
    $router->get('/admin', 'AdminController@index');
});

// Requiere permiso específico
$router->group(['middleware' => ['auth', 'permission:users.manage']], function ($router) {
    $router->get('/users', 'UserController@index');
});
```

### En Controladores

```php
namespace Apps\Products\Controllers;

use Apps\ApolloAuth\Facades\Auth;

class ProductController
{
    public function index(Request $request)
    {
        // Obtener usuario autenticado
        $user = $request->user();
        // o
        $user = Auth::user();
        
        // Verificar permisos
        if (!$user->hasPermission('products.view')) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        
        // Tu lógica...
    }
}
```

## API Endpoints

### Autenticación Pública

```
POST /api/auth/login
POST /api/auth/register
```

### Autenticación Protegida

```
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

## Modelos

### User

```php
use Apps\ApolloAuth\Models\User;

$user = User::find(1);

// Métodos de autenticación
$user->verifyPassword('password');
$user->isActive();
$user->hasVerifiedEmail();
$user->updateLastLogin();

// Roles y permisos
$user->hasRole('admin');
$user->hasAnyRole(['admin', 'moderator']);
$user->hasPermission('users.create');
$user->assignRole('moderator');
$user->removeRole('user');
$user->getAllPermissions();

// Sesiones
$user->sessions;
$user->activeSessions();
$user->revokeAllSessions();
```

### Role

```php
use Apps\ApolloAuth\Models\Role;

$role = Role::where('name', 'admin')->first();

// Permisos
$role->hasPermission('users.create');
$role->addPermission('posts.delete');
$role->removePermission('posts.delete');

// Usuarios
$role->users;
```

## Configuración

### Variables de Entorno (.env)

```env
JWT_SECRET_KEY=your_super_secure_secret_key_32_chars_minimum
JWT_ALGORITHM=HS256
JWT_ISSUER=apollo-api.local
JWT_AUDIENCE=apollo-client
JWT_EXPIRY=3600

RATE_LIMIT_MAX_ATTEMPTS=5
RATE_LIMIT_WINDOW=900
```

### Configuración (config/auth.php)

```php
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

## Roles por Defecto

- **admin**: Acceso completo (*)
- **moderator**: Moderación de contenido
- **user**: Usuario regular
- **guest**: Acceso limitado

## Credenciales por Defecto

Después de ejecutar los seeders:

- Email: `admin@apollo.local`
- Password: `admin123`

## Seguridad

- Passwords hasheados con `PASSWORD_DEFAULT`
- Tokens JWT con expiración configurable
- Rate limiting por IP
- Gestión de sesiones con revocación
- Validación de tokens en cada request
- Middleware de protección por roles y permisos

## Extensión

### Agregar Nuevos Roles

```php
use Apps\ApolloAuth\Models\Role;

Role::create([
    'name' => 'editor',
    'display_name' => 'Editor',
    'description' => 'Can edit content',
    'permissions' => ['content.create', 'content.edit', 'content.view'],
    'is_system' => false
]);
```

### Agregar Permisos a Rol Existente

```php
$role = Role::where('name', 'editor')->first();
$role->addPermission('content.publish');
```

### Asignar Rol a Usuario

```php
$user = User::find(1);
$user->assignRole('editor', $assignedBy = auth()->id());
```

## Licencia

MIT