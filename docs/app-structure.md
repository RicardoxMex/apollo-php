# Estructura de Apps en Apollo Framework

## Configuración de Apps

Cada app en el directorio `apps/` debe tener un archivo `app.json` que define su configuración:

```json
{
    "name": "users",
    "version": "1.0.0",
    "description": "Users management app",
    "author": "Your Name",
    "prefix": "api/users",
    "providers": [
        "Apps\\Users\\Providers\\UsersServiceProvider"
    ],
    "routes": ["api.php"]
}
```

### Campos de configuración:

- **name**: Nombre de la app (requerido)
- **version**: Versión de la app
- **description**: Descripción de la app
- **author**: Autor de la app
- **prefix**: Prefijo de las rutas (opcional, por defecto: `api/{name}`)
  - Ejemplo: `"prefix": "api/users"` → rutas accesibles en `/api/users/*`
  - Ejemplo: `"prefix": "v1/users"` → rutas accesibles en `/v1/users/*`
  - Ejemplo: `"prefix": "users"` → rutas accesibles en `/users/*`
- **providers**: Array de clases ServiceProvider a registrar
- **routes**: Array de archivos de rutas a cargar desde `Routes/`

## Estructura de Directorios

```
apps/
└── users/
    ├── app.json                    # Configuración de la app
    ├── config/                     # Configuraciones específicas
    ├── Controllers/                # Controladores
    │   └── UserController.php
    ├── Middleware/                 # Middlewares
    │   ├── Authenticate.php
    │   └── RoleMiddleware.php
    ├── Models/                     # Modelos
    │   └── User.php
    ├── Providers/                  # Service Providers
    │   └── UsersServiceProvider.php
    ├── Repositories/               # Repositorios
    │   └── UserRepository.php
    ├── Routes/                     # Archivos de rutas
    │   └── api.php
    ├── Services/                   # Servicios
    │   └── UserService.php
    └── Serializers/                # Serializadores (opcional)
```

## Service Providers

Los Service Providers deben:
1. Estar en la carpeta `Providers/`
2. Registrarse en el array `providers` del `app.json`
3. Extender `Apollo\Core\Container\ServiceProvider`

Ejemplo:

```php
<?php
namespace Apps\Users\Providers;

use Apollo\Core\Container\ServiceProvider;

class UsersServiceProvider extends ServiceProvider {
    public function register(): void {
        // Registrar servicios, repositorios, controladores, middlewares
    }
    
    public function boot(): void {
        // Configuraciones adicionales después del registro
    }
}
```

## Rutas

Las rutas deben:
1. Estar en la carpeta `Routes/`
2. Registrarse en el array `routes` del `app.json`
3. Usar la sintaxis `[Controller::class, 'method']`

Ejemplo:

```php
<?php
use Apps\Users\Controllers\UserController;

/** @var \Apollo\Core\Router\Router $router */

// Rutas públicas
$router->get('/', [UserController::class, 'index'])->name('users.index');
$router->get('/{id}', [UserController::class, 'show'])->where(['id' => '\d+'])->name('users.show');

// Rutas con middleware
$router->group(['middleware' => ['auth']], function($router) {
    $router->post('/', [UserController::class, 'store'])->name('users.store');
    $router->put('/{id}', [UserController::class, 'update'])->where(['id' => '\d+'])->name('users.update');
});
```

## Controladores

Los controladores deben:
1. Estar en la carpeta `Controllers/`
2. Extender `Apollo\Core\Http\Controller`
3. Recibir dependencias a través del constructor

Ejemplo:

```php
<?php
namespace Apps\Users\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Container\Container;
use Apps\Users\Services\UserService;

class UserController extends Controller {
    private UserService $userService;
    
    public function __construct(Container $container, UserService $userService) {
        parent::__construct($container);
        $this->userService = $userService;
    }
    
    public function index() {
        $users = $this->userService->getAllUsers();
        return $this->json(['success' => true, 'data' => $users]);
    }
}
```

## Middlewares

Los middlewares deben:
1. Estar en la carpeta `Middleware/`
2. Registrarse en el ServiceProvider
3. Implementar el método `handle(Request $request, Closure $next)`

Ejemplo de registro:

```php
// En UsersServiceProvider::register()
$this->container->bind('auth', fn($container) => new Authenticate());
$this->container->bind('role.admin', fn($container) => new RoleMiddleware(['admin']));
```

## Carga de Apps

Las apps se cargan automáticamente desde `config/app.php`:

```php
'apps' => [
    'enabled' => ['users', 'products'],
    'autoload' => true,
],
```

El framework:
1. Lee el `app.json` de cada app
2. Registra los providers listados en `providers`
3. Carga las rutas listadas en `routes`
4. Aplica el prefijo configurado en `prefix` (por defecto: `api/{appName}`)

## Ejemplos de Prefix

### Prefix por defecto (api/users)
```json
{
    "name": "users",
    "prefix": "api/users"
}
```
Rutas accesibles en: `/api/users`, `/api/users/1`, etc.

### Prefix versionado (v1/users)
```json
{
    "name": "users",
    "prefix": "v1/users"
}
```
Rutas accesibles en: `/v1/users`, `/v1/users/1`, etc.

### Prefix simple (users)
```json
{
    "name": "users",
    "prefix": "users"
}
```
Rutas accesibles en: `/users`, `/users/1`, etc.

### Sin prefix (raíz)
```json
{
    "name": "users",
    "prefix": ""
}
```
Rutas accesibles en: `/`, `/1`, etc. (no recomendado)
