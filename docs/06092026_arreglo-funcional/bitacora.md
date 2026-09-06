# Bitácora — Arreglo funcional del framework

| Fecha | Qué pasó | Evidencia |
|---|---|---|
| 2026-09-06 | Validación inicial: lint 82 archivos OK; composer.json válido (lock desactualizado); CLI bootea; smoke HTTP 200/401/404; DB 500 (pdo_mysql ausente en `C:\tools\php84`); phpunit exit 1 sin config; hallazgos FRAMEWORK-01..09 | `known-issues.md`, informe de validación |
| 2026-09-06 | SEC-01: borrados stubs demo (Users Authenticate/RoleMiddleware); `auth`→AuthMiddleware (JWT real); `role.admin`/`role.user`→RoleMiddleware con `hasAnyRole`; admin.php → `role.admin` | rutas sin 500 (test `admin_route...` 401), suite |
| 2026-09-06 | SEC-02: `file`/`line`/trace y mensajes internos gated por `APP_DEBUG` en Router::callAction, Kernel, Pipeline | test `action_error_does_not_leak` |
| 2026-09-06 | FIX-01..04, CLI-01: JWTManager único (core), rutas Products (27), apollo.json alineado, `.env.example`, registerApp case-insensitive, sin log "✅" por request | `php apollo route:list` → 27 rutas |
| 2026-09-06 | TST-01: `phpunit.xml.dist` + ContainerTest/RouterTest/JWTManagerTest/ApiSmokeTest | PHPUnit OK 26/26 → 29/29 (58 aserciones) |
| 2026-09-06 | Review independiente (agente Reviewer): APPROVED → 2 MAJOR (attributes['user'] vacío; callAction filtra mensaje) + menores; correcciones aplicadas | responder session d215fd80 |
| 2026-09-06 | Corregido en review: `attributes['user']` poblado + rutas /profile/stats/demo con datos seguros; helpers `auth()` → AuthService::class; `Request::setUser` sin type-hint roto; auth()→[AuthService; `.gitignore` + `.phpunit.cache/`; docs actualizadas; test_middleware.php reescrito | suite 29/29; `php test_middleware.php` 5/5 ✅ |
| 2026-09-06 | `make:model` probado en vivo (Widget→widgets) y limpiado; SystemReport 27 rutas | CLI OK |
| 2026-09-06 | MEM-01: known-issues resuelto, work folder creado | este archivo |
| 2026-09-06 | FRAMEWORK-10: escrituras `/api/products/{store,update,destroy}` protegidas con `middleware ['auth']` (JWT real); lecturas públicas; `apps/Products/Routes/api.php` queda como ejemplo comentado de uso del framework | suite 31/31 (64 aserciones); route:list 27 rutas; test_middleware 5/5 |
| 2026-09-06 | **Incidente de caso en Windows**: `apps/users/Routes/api.php` y `apps/products/app.json` desaparecieron del working tree (git rastrea las apps en minúsculas, el FS expone PascalCase; la colisión hizo que git los viera como borrados y las rutas de Users cayeran). Restaurados y verificados | git status → M; rutas 27 |
| 2026-09-06 | **Herramienta de scaffolding completa**: 8 generadores (`make:app`, `make:controller`, `make:middleware`, `make:migration`, `make:model`, `make:repository`, `make:seeder`, `make:service`). `make:app` crea app modular (app.json + dirs + controller + rutas público/private + provider) y **auto-registra** en `config/apps.php` y `apollo.json`. Probados en vivo con app desechable Blog (32 rutas) y limpiados. Bugs detectados por la prueba en vivo: tabla derivada de migración, sufijo Seeder duplicado, CRLF en la inserción de config/apps.php (delimitador regex `(`) — corregidos | help 12 comandos; suite 31/31; 27 rutas tras cleanup |

## Pendientes (decisión consciente)

- **`git mv` de `apps/` a caso canónico (PascalCase) — AHORA PRIORITARIO**: git rastrea `apps/users/` y `apps/products/` en minúsculas; el 2026-09-06 dos archivos (`users/Routes/api.php`, `products/app.json`) desaparecieron del working tree por la colisión de caso en Windows (git los vio como `D`). Un `git mv apps/users apps/Users && git mv apps/products apps/Products` estabiliza el árbol y arregla la portabilidad Linux (PSR-4 case-sensitive).
- Validación end-to-end con MySQL real (migrations/seeds, login JWT, endpoints DB) — requiere PHP con `pdo_mysql` + `.env` + servidor MySQL.
- Revisar `composer.lock` desactualizado vs `composer.json` (`composer update --lock`).