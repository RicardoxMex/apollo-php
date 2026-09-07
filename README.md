# Apollo Framework

Mini-framework **PHP** para construir **APIs REST modulares**, inspirado en Django REST Framework. Apps independientes sobre un kernel propio (`core/`), sin dependencias de otros frameworks — solo PSR (container, HTTP) y phpdotenv.

```text
┌────────────────────────────────────────────────────────┐
│ Public/index.php · apollo (CLI)                         │
│   → Application → config → providers → apps → Router   │
│   → middleware → Controller → Service → Repository     │
│   → Model → MySQL | SQLite → JSON                      │
└────────────────────────────────────────────────────────┘
```

- **Licencia:** MIT · **Requiere:** PHP >= 8.3 (ext-pdo) + Composer

## ✨ Características

- **Apps modulares** al estilo Django: `apps/<App>/` con `app.json` (prefijo, providers, rutas) y auto-registro.
- **Kernel propio:** `Application`, `Config`, `Container` (DI), `Router`, `Http`, `Database`, `Auth`, `Validation`, `Console`.
- **Router** con grupos, middleware, parámetros (`{id}` con `where`), rutas nombradas y `GET/POST/PUT/PATCH/DELETE`.
- **DB intercambiable**: MySQL **o** SQLite (`DB_DRIVER`) con Schema/Blueprint driver-aware, migraciones y seeders.
- **Auth JWT real** (`ApolloAuth`): login, sesiones con revocación, refresh, logout-all, roles/permisos.
- **Módulo de acceso (core, activable)**: tabla `permissions` + pivot `role_permissions`, gates `role.admin`/`role.user`, endpoints admin de gestión.
- **Validación (core)**: `Validator::make()`, 27 reglas (`required`, `email`, `min/max/between`, `in`, `unique/exists` con BD…), mensajes por campo, reglas personalizadas (closure/`Rule`/`extend`) y `validate()` en el Controller base.
- **Realtime opcional**: WebSockets (OpenSwoole), canales público/privado/presence, Redis opcional con fallback local, notificaciones (DB + realtime), SDK JS.
- **CLI completo**: 17 comandos (generadores `make:*`, `route:list`, `realtime:*`, self-check…).
- **Testing**: suite PHPUnit sin DB + integración SQLite opcional.

## 🚀 Empezar (cómo arrancar)

```bash
# 1. Dependencias
composer install

# 2. Entorno
cp .env.example .env          # (o php -r "copy('.env.example', '.env');")
#    edita al menos: APP_DEBUG, DB_*, JWT_SECRET_KEY
#    Genera una clave JWT:  php -r "echo bin2hex(random_bytes(32));"

# 3. Base de datos (MySQL o SQLite)
#    SQLite (desarrollo rápido, requiere extension=pdo_sqlite):
#      DB_CONNECTION=sqlite / DB_DATABASE=database/development.sqlite  (en .env)
#    MySQL:
#      DB_CONNECTION=mysql / DB_HOST / DB_USERNAME / DB_PASSWORD ...
#
php setup_database.php        # crea todas las tablas (migraciones 001..009)
php run_seeders.php           # roles (admin/moderator/user/guest) + usuario admin

# 4. Servidor de desarrollo
composer start                # = php -S localhost:8000 -t public

# 5. Comprobar
curl http://localhost:8000/api/users/test
```

Credenciales del seeder: **admin@apollo.local / admin123** (login real JWT en `POST /api/auth/login`).

## ⚙️ Configuración (`.env`)

| Clave | Qué controla |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | Aplicación / depuración |
| `DB_CONNECTION`, `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base de datos (`mysql` \| `sqlite`) |
| `JWT_SECRET_KEY`, `JWT_ALGORITHM`, `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_EXPIRY`, `JWT_REFRESH_TTL` | Auth (obligatorio `JWT_SECRET_KEY`) |
| `AUTH_ACCESS_ENABLED` | Módulo de roles/permisos del core (default `true`) |
| `REALTIME_DRIVER`, `REALTIME_HOST`, `REALTIME_PORT`, `REALTIME_APP_SECRET`, `REDIS_HOST/PORT`… | Realtime opcional (`auto` \| `redis` \| `local`) |

Detalle completo: [`.env.example`](.env.example).

## 🧰 Comandos CLI

```
php apollo <command>
```

| Comando | Qué hace |
|---|---|
| `help` | Lista todos los comandos (fuente única: `Kernel::getCommands()`) |
| `route:list` | Lista las rutas registradas (33 por defecto) |
| `system:report` | Reporte del sistema (versión, apps, rutas, entorno) |
| `test` | Self-check del framework (config, apps, rutas, JWT — **sin DB**) |
| `make:app Blog` | Crea una app modular completa (app.json, controller, rutas, provider) y **la auto-registra** |
| `make:controller Name --app=users` | Crea un controlador |
| `make:middleware Name --app=users` | Crea un middleware |
| `make:model Name --app=users` | Crea un modelo (tabla inferida: Product → products) |
| `make:migration create_xxx_table` | Crea una migración con numeración secuencial |
| `make:seeder Name` | Crea un seeder |
| `make:service Name --app=users` | Crea un servicio (capa de negocio) |
| `make:repository Name --app=users` | Crea un repositorio (capa de datos) |
| `realtime:start` | Arranca el servidor WebSocket (**requiere extension=openswoole**) |
| `realtime:stop` / `realtime:restart` | Detiene / reinicia el servidor WS |
| `realtime:status` | Estado del servidor (driver, puerto, pid) |
| `realtime:test` | Health check de realtime (PHP, OpenSwoole, Redis, DB, config) |

Otros scripts: `php test_middleware.php` (smoke de middlewares sin DB) · `php setup_database.php` · `php run_seeders.php`.

## 🏗️ Cómo funciona

### Boot (cómo arranca una petición)

1. `public/index.php` (o `apollo` para CLI) → `vendor/autoload.php` → `.env` opcional.
2. `new Application(root)` → se registran: `path.*`, `config` (todos los `config/*.php`), `router`.
3. Se registran los **providers** (`config/providers.php`: core + app) y se **bootean**.
4. Se registran las **apps** (`config/apps.php`): cada `app.json` aporta providers, prefijo y archivos de rutas.
5. `handle(Request)` → el **Router** resuelve `método + uri` → pipeline de **middleware** → action (Closure o `[Controller::class, 'method']` resuelto con DI).
6. Controller → Service → Repository → Model → PDO (`MySQL`/`SQLite`) → respuesta **JSON** (`success`/`data`/`meta` o `error`).

### Estructura

```text
core/                 kernel del framework (Apollo\Core\)
  Application.php     bootstrap
  Config.php          repositorio de config
  Container/          DI container (bind/singleton/alias/call)
  Router/             router + pipeline de middleware
  Http/               Kernel, Request, Response, Controller base
  Database/           Model, QueryBuilder, Migration, Schema/Blueprint,
                      Connection (MySQL|SQLite), Repository base
  Validation/         Validator + RuleRegistry (27 reglas, mensajes, custom) + ValidationException
  Auth/               JWT + módulo de acceso (HasRoles, Role, Permission,
                      RoleMiddleware, PermissionMiddleware) — activable
  Realtime/           WebSockets/EventBus/Notificaciones (opcional)
  Console/            Kernel CLI + 17 comandos
  Providers/          providers del core
  Support/            helpers (app, config, env, realtime, ...)
apps/                 aplicaciones modulares (Apps\)
  ApolloAuth/         auth JWT, sesiones, roles/permisos admin (endpoints /api/auth/*)
  Users/              CRUD de usuarios de referencia (/api/users/*)
  Products/           CRUD de ejemplo (lecturas públicas + escrituras con auth)
  Realtime/           REST API opcional del módulo realtime (/v1/*) — no registrada por defecto
config/               config/*.php (app, apps, auth, providers, realtime)
database/             migrations/ (001..009) + seeds/
public/               index.php + js/realtime.js (SDK)
docs/                 manual del usuario + work folders de Arches
tests/                suite PHPUnit (sin DB + integración SQLite)
```

### Convenciones

- Namespaces: `Apollo\Core\` → `core/`, `Apps\` → `apps/`, `Tests\` → `tests/`.
- Controllers delgados → Services (lógica) → Repositories (datos) → Models.
- Respuestas JSON con plantilla `success/data` o `error`.
- Rutas en `apps/<App>/Routes/api.php` con el DSL del router (`->name()`, `->where()`, `->middleware()`, `group()`).
- Docs y comentarios en español; `.ai/` (sistema Arches) ignorado por git.

## 🔐 Módulos destacados

- **Auth JWT** (ApolloAuth): `POST /api/auth/login|register`, `GET /api/users/profile`, `refresh`, `logout-all`, sesiones revocables. Más: [docs/authentication-system.md](docs/authentication-system.md).
- **Roles y permisos (core)**: `GET /api/auth/admin/roles`, CRUD de roles, catálogo de permisos, `role.admin`/`role.user` en rutas. Configurable con `AUTH_ACCESS_ENABLED=false` (módulo inerte).
- **Realtime**: `Realtime::broadcast('orders', 'order.created', [...])` desde PHP y `new RealtimeClient(...)` desde JS. [docs/realtime.md](docs/realtime.md).

## 🧪 Testing

```bash
composer test                      # PHPUnit: 125 tests, 332 aserciones
                                   #  - sin DB: router, container, JWT, auth, realtime (buses/channels)
                                   #  - integración SQLite opcional (requiere extension=pdo_sqlite)
php apollo test                    # self-check rápido (sin DB)
php test_middleware.php            # smoke de middlewares (200/401/404)
```

## 📚 Documentación

- [docs/README.md](docs/README.md) — índice del manual
- [docs/app-structure.md](docs/app-structure.md) — estructura de apps y `app.json`
- [docs/authentication-system.md](docs/authentication-system.md) — auth, roles y permisos
- [docs/validation.md](docs/validation.md) — motor de validación del core (reglas, mensajes, custom)
- [docs/cli-commands.md](docs/cli-commands.md) — cómo crear comandos CLI propios
- [docs/realtime.md](docs/realtime.md) — módulo realtime (websockets/notificaciones)

## 🧪 Try it

```bash
composer install && cp .env.example .env
php setup_database.php && php run_seeders.php
composer start
curl http://localhost:8000/api/users/test
curl -X POST http://localhost:8000/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@apollo.local","password":"admin123"}'
```