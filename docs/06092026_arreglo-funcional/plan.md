# Plan — Arreglo funcional del framework

## Meta (L1)

Dejar el miniframework 100% funcional en los planos validables sin DB: auth JWT real, sin fugas de info, tests que pasan, CLI coherente y piezas muertas resueltas.

## Hitos (L2)

1. **Seguridad/auth** — JWT real cableado; demo eliminado; sin 500 en admin; sin fugas. ✅ (27 → 29 tests)
2. **Funcionalidad** — Products con rutas; JWT único; config coherente; `.env.example`. ✅
3. **Testing/CLI** — `phpunit.xml.dist` + suite verde (29/58); `help`, `test`, `make:model` OK. ✅
4. **Registros** — work folder + `known-issues.md` actualizado + memory. ✅

## Tareas (L3)

| ID | Tarea | Riesgo | Depende de | Estado |
|---|---|---|---|---|
| SEC-01 | Cablear AuthMiddleware/role.admin; borrar stubs demo (Users Authenticate/RoleMiddleware) | HIGH | — | COMPLETED |
| SEC-02 | Gatear fugas de info (Router::callAction, Kernel, Pipeline) | HIGH | — | COMPLETED |
| SEC-03 | Review independiente + correcciones (attributes['user'], helpers/auth, Request::setUser type) | HIGH | SEC-01, SEC-02 | COMPLETED |
| FIX-01 | Deduplicar JWTManager (canonical core) | MEDIUM | — | COMPLETED |
| FIX-02 | Rutas CRUD Products + app.json | LOW | — | COMPLETED |
| FIX-03 | Alinear apollo.json + crear .env.example | LOW | — | COMPLETED |
| FIX-04 | registerApp case-insensitive + quitar log por request + .gitignore cache | MEDIUM | — | COMPLETED |
| CLI-01 | Kernel fuente única help; TestCommand self-check; MakeModelCommand | LOW | — | COMPLETED |
| TST-01 | phpunit.xml.dist + 4 suites de test verdes | MEDIUM | SEC-*, FIX-* | COMPLETED |
| DOC-01 | middleware_examples.md + app-structure.md + test_middleware.php + README CLI | LOW | CLI-01 | COMPLETED |
| MEM-01 | known-issues.md, context.md, work folder | LOW | todo | COMPLETED |

Estado canónico en `.ai/state/store/` (sin sesión activa: trabajo ejecutado como pase único de diagnóstico + fix; los registros humanos viven en esta carpeta).