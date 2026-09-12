# Plan — WebSockets / Workerman

> Plan humano del trabajo. Formato canónico: `templates/plan.md` (`.ai/templates/plan.md`). Lo registra el engine en `store/plan.json` y descompone las tareas en `store/tasks.json` (`workflows/hierarchical-planning.md`).

## Meta (L1)

Migrar el servidor WebSocket del módulo `core/Realtime/` de OpenSwoole a Workerman, manteniendo la arquitectura existente y entregando la API funcional del spec 24-puntos (autenticación JWT, mapeo user→conexiones, NotificationService, heartbeat, reconexión, persistencia, seguridad, WSS, Redis preparado).

## Hitos (L2)

1. **M1 — Capa core Workerman + transporte cross-process**: instalar `workerman/workerman`, reescribir `WebSocketServer` con multiplex HTTP+WS sobre el mismo puerto, crear `ConnectionAuthenticator` (JWT) + `HttpEventBus`, mode-aware `RealtimeManager`, endpoints `/internal/push|status|auth`, heartbeat timer. Salida: `php apollo realtime:start` arranca en Windows y Linux; `php apollo realtime:test` reporta Workerman; CLI `realtime:start/stop/restart/status/test` operativos.
2. **M2 — NotificationService + guía de integración REST + SDK JS**: `NotificationService::sendToUser` (persiste + emite), actualizar `RealtimeChannel` al frame `{"event":"notification","data":{…}}`, documentar la guía de integración REST en `docs/websockets.md §13` (NO crear `apps/Realtime/` — es responsabilidad del integrador), extender `public/js/realtime.js` con `token` + `on/emit/off` + protocolo `{event,data}`. Salida: suite verde, end-to-end manual posible con `curl` + navegador.
3. **M3 — Documentación, configuración y verificación final**: `docs/websockets.md` nuevo (14 puntos del spec §22), `docs/realtime.md` actualizado sin OpenSwoole, `docs/README.md` enlazado, `config/realtime.php` + `.env.example` con `WEBSOCKET_*` y fallback `REALTIME_*`, `composer.json` con `scripts.websocket`, lint + `composer test` + `php apollo route:list` + `php apollo realtime:test` verdes. Salida: gates G1–G7 pass.

## Tareas (L3)

| ID | Tarea | Riesgo | Depende de | Estado |
|---|---|---|---|---|
| WS-01 | Instalar `workerman/workerman`, ajustar `composer.json` (require + suggest + scripts + post-autoload-dump) y `.env.example` (alias `WEBSOCKET_*`); sin extensión `ext-openswoole`. | LOW | — | PENDING |
| WS-02 | Reescribir `core/Realtime/WebSocket/WebSocketServer.php` con Workerman (multiplex HTTP+WS mismo puerto, handshake, onMessage, onClose, timer de heartbeat/sweep), crear `websocket/server.php` (entry), `core/Realtime/Auth/ConnectionAuthenticator.php` (JWT en handshake). | HIGH | WS-01 | PENDING |
| WS-03 | Crear `core/Realtime/Bus/HttpEventBus.php` + extender `ConnectionManager` (`sendToUser`, `sweep`) + `RealtimeConfig` (nuevas claves) + `RealtimeManager` (`inProcess` mode-aware, `sendToUser` helper) + actualizar `RealtimeServiceProvider` (app mode) + wiring del endpoint `/internal/push`/`/internal/status`/`/internal/auth` en el servidor. | HIGH | WS-02 | PENDING |
| WS-04 | Crear `core/Realtime/Notifications/NotificationService.php` (sendToUser persiste+emite), actualizar `RealtimeChannel` al frame `{"event":"notification","data":{…}}` (cambio de event name intencional), `realtime:test` ajustado a Workerman, comandos `realtime:start/stop/restart/status` reescritos, `Runtime/pid` en `runtime/`. | HIGH | WS-03 | PENDING |
| WS-05 | Reescribir `docs/websockets.md` §13 como **guía de integración REST** (NO crear `apps/Realtime/`; el integrador crea su propia app siguiendo la guía: registrar en `config/apps.php`, controller con `MySqlNotificationRepository` + autorización por dueño, rutas con middleware `auth`). | MEDIUM | WS-04 | PENDING |
| WS-06 | Extender `public/js/realtime.js` con `token` en handshake, `on/emit/off` (API top-level), manejo del frame `{event,data}` (`socket.on('notification', cb)`); mantener API de `channel().listen()` por BC; reconexión + heartbeat ya presentes, verificar. | MEDIUM | WS-04 | PENDING |
| WS-07 | Tests: `ConnectionManager::sendToUser` test, `NotificationService` test, `HttpEventBus` test (con test server HTTP embebido), actualizar `NotificationManagerTest` (event name `notification.received`→`notification`); verificar `composer test` verde. | MEDIUM | WS-05, WS-06 | PENDING |
| WS-08 | Documentación: `docs/websockets.md` (14 puntos spec §22), actualizar `docs/realtime.md` (sin OpenSwoole, con Workerman), actualizar `docs/README.md` (enlaza), actualizar `docs/realtime.md` secciones a las nuevas env vars, troubleshooting Windows. | LOW | WS-07 | PENDING |

> Estado canónico en `.ai/state/store/` (tasks.json, graph.json) — este archivo es la vista humana.

## Grafo de dependencias

```
WS-01 ──▶ WS-02 ──▶ WS-03 ──▶ WS-04 ──┬──▶ WS-05 ──┐
                                          │              ├──▶ WS-07 ──▶ WS-08
                                          └──▶ WS-06 ──┘
```

Cluster M1: {WS-01, WS-02, WS-03, WS-04} — sequential (capa core con dependencias encadenadas; el coste de fan-out no lo justifica y los archivos se solapan).  
Cluster M2: {WS-05, WS-06} — paralelo factible (controllers vs SDK JS, archivos disjuntos; comparten `RealtimeChannel` pero el cambio de `NotificationService` ya está cerrado en WS-04).  
Cluster M3: {WS-07, WS-08} — sequential (tests necesitan el código y los datos estables; docs van al final).

## Riesgos y mitigaciones

- **Windows sin daemon**: `realtime:start` en Windows corre en foreground; `stop`/`restart` usan taskkill sobre el pid file. Documentado en troubleshooting.
- **Workerman no soporta multi-Worker en un archivo en Windows**: el diseño HTTP+WS sobre el mismo puerto (D1) evita ese caso.
- **Cross-process**: el `HttpEventBus` puede fallar si el servidor está caído → fail-open (D4) garantiza que la persistencia cubre el caso offline (spec §12).
- **JWT inválido en producción**: `realtime:test` lo detecta; el `onWebSocketConnect` cierra la conexión sin revelar detalles.
- **Tests rotos por el cambio de event name**: 1 test afectado (`NotificationManagerTest`), ajuste intencional documentado.
