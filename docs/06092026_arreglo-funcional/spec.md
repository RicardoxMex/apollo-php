# Spec — Arreglo funcional del framework

> Orden del usuario: "valida mi miniframework y ve qué le hace falta para que sea 100% funcional" + "si" a ejecutar el arreglo completo. Fuente de verdad de requisitos: hallazgos de la validación (FRAMEWORK-01..09, `known-issues.md` 2026-09-06).

## Contexto

La validación (2026-09-06) confirmó que el núcleo (boot, config, DI, router, middleware, CLI) funciona, pero con gaps críticos: autenticación demo (tokens hardcodeados), rutas admin rotas, sin tests, JWT duplicado, app Products inerte, config contradictoria, filtraciones de información en errores, CLI inconsistente y sin `.env.example`.

## Requisitos (criterios de aceptación)

1. **Auth real**: `auth` = JWT de ApolloAuth (AuthMiddleware/AuthService); sin tokens demo; rutas `/api/auth/admin/*` sin 500.
2. **Sin fugas**: respuestas 500 sin `file`/`line`/trace/mensajes internos con `APP_DEBUG=false`.
3. **Tests**: `composer test` pasa (suite sin DB); cubre router, container, JWT, auth middleware y smoke HTTP (200/401/404/400).
4. **JWT único**: un solo `JWTManager` (core), defaults alineados con `config/auth.php`.
5. **Products funcional**: rutas CRUD `/api/products/*`.
6. **Config coherente**: `apollo.json` refleja `config/apps.php` + prefijos reales.
7. **CLI**: `help` con lista única desde Kernel; `test` hace self-check real; `make:model` implementado (documentado).
8. **Entorno**: `.env.example` con todas las claves en uso.
9. **Portabilidad/noise**: `registerApp` case-insensitive; sin `error_log("✅…")` por request; `phpunit.cache/` en `.gitignore`.

## Fuera de alcance

- Validación con DB real (requiere MySQL + extensión `pdo_mysql`): login JWT end-to-end, migrations/seeds, endpoints con DB.
- Autorizar o no las escrituras públicas de `/api/products` (decisión consciente pendiente).
- `git mv` de directorios `apps/` a caso canónico (issue de portabilidad, requiere tocar el índice de git).