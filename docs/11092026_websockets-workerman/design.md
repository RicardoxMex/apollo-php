# Design — WebSockets / Workerman

> Solo si el tema lo necesita (arquitectura, contratos, decisiones). Formato canónico: `templates/decision.md` (`.ai/templates/decision.md`) para decisiones; decisiones significativas también se registran en `.ai/specs/decisions/` y `.ai/project/decisions.md`.

## Contexto

El módulo `core/Realtime/` (commit `d437b9f`) entrega un servidor WebSocket sobre OpenSwoole, canales públicos/privados/presence, notificaciones con canales `database` y `realtime`, abstracción de bus (local + Redis) y un SDK JS. La extensión `ext-openswoole` no es instalable en Windows (`pecl install openswoole` falla), y la máquina del usuario (`win32`) corre `php 8.4.14` sin esa extensión, así que el servidor no se puede ni arrancar. Hay que sustituir el transporte por **Workerman** (paquete Composer puro-PHP, sin extensión) preservando la arquitectura, los contratos y la API pública.

## Opciones consideradas

### O1 — Sustitución 1:1: Workerman donde estaba OpenSwoole, mismo EventBus, mismo flujo.

- ✅ Cambio mínimo, reusa `ConnectionManager`, `MessageHandler`, `ChannelManager`, `EventDispatcher`, `NotificationManager`, `Broadcaster`, `MySqlNotificationRepository`, `ChannelAuthenticator`.
- ✅ Tests existentes (`ConnectionManagerTest`, `MessageHandlerTest`, `ChannelManagerTest`, `ChannelAuthenticatorTest`, `BroadcasterTest`, `RealtimeManagerTest`, `HeartbeatTest`, `NotificationRepositoryTest`, `NotificationManagerTest`) siguen siendo válidos en su mayoría.
- ⚠️ El **transporte app → servidor** sigue sin resolverse: el `LocalEventBus` es in-memory y el proceso HTTP (`php -S` / web) no comparte memoria con el proceso del servidor WebSocket. Con el driver `local`, las notificaciones publicadas desde un request HTTP no llegan al servidor.

### O2 — O1 + `HttpEventBus` (app-side) + endpoint interno HTTP en el servidor.

- ✅ Resuelve el transporte cross-process sin introducir Redis.
- ✅ Mantiene la abstracción `EventBus`; el driver `local` elige `HttpEventBus` en el proceso de la app, `LocalEventBus` en el proceso del servidor.
- ✅ Sin dependencias nuevas: `HttpEventBus` usa `stream_context_create` + `file_get_contents` (mismo patrón que el `RedisClient` nativo del módulo).
- ⚠️ Acopla el driver `local` a un endpoint HTTP interno adicional: el servidor debe exponerse en **un segundo puerto** o en el **mismo puerto** con un protocolo dual HTTP+WS.

### O3 — O1 + `RedisEventBus` (consumidor en el servidor) + workerman/channel para multi-proceso.

- ✅ Arquitectura multi-instancia completa desde v1.
- ❌ Requiere `workerman/channel` (dependencia adicional) y un Worker consumidor Redis dedicado; en Windows un archivo no puede definir varios Workers → dos archivos `start_ws.php` + `start_channel.php` + un `Channel\Server`; complejo de operar.
- ❌ En la máquina de desarrollo actual no hay Redis instalado; el camino `local` seguiría roto.

**Decisión:** **O2**. Mantiene el módulo coherente con su arquitectura actual (un solo `EventBus` por proceso, driver decide cuál), introduce una dependencia nueva (Workerman) y ninguna más, y funciona en Windows desde el primer día. La ruta multi-instancia con Redis queda preparada por la abstracción `EventBus` y se documenta explícitamente como paso 2 (requeriría `workerman/channel` y la integración server-side, ya no in scope para esta entrega).

## Decisión

### D1 — Servidor Workerman: un solo `Worker` `websocket://`, sin segundo puerto.

Workerman en Windows no soporta múltiples `Worker` en un solo archivo (`multi workers init in one php file are not support`). Mantener **un único `Worker` `websocket://`** evita ese error y mantiene el código idéntico en Windows y Linux. El servidor se limita a:

- Aceptar conexiones WebSocket en `WEBSOCKET_HOST:WEBSOCKET_PORT` (default `127.0.0.1:8080`).
- `onWebSocketConnect`: autenticar JWT, crear `Connection`, asociar `user_id`.
- `onMessage`: `MessageHandler`.
- `onClose`: `ConnectionManager::disconnect`.
- `Timer`: heartbeat (ping + sweep) y **polling de notificaciones** (ver D4).

El endpoint HTTP interno para cross-process push (originalmente propuesto) se reemplaza por **polling server-side desde la tabla `notifications`** — ver D4 revisado. Esto elimina la necesidad de un segundo puerto, un segundo proceso, o IPC, y funciona idéntico en Windows y Linux.

### D2 — JWT en handshake, nunca `user_id` del cliente.

- `?token=<jwt>` en la URL (los navegadores no pueden fijar `Authorization` en el handshake WS).
- Si el cliente envía `Authorization: Bearer …` en el handshake (clientes Node/CLI), se acepta.
- Validación con `Apps\ApolloAuth\Services\AuthService::authenticateFromToken()` (mecanismo existente: `JWTManager::validateToken` + chequeo de `user_sessions` con `is_revoked=false` y `expires_at>now`). Devuelve `User` o `null`.
- Conexión queda asociada a `user_id` en el `ConnectionManager`. El cliente no puede cambiarlo.
- Si la app quiere suscribir a `private-user.{id}`, el servidor exige que `user_id` del path coincida con el autenticado (sin necesidad de ticket HMAC para ese canal concreto cuando es su propio canal). Para cualquier otro canal privado/presence se exige ticket firmado (mecanismo actual intacto).

### D3 — NotificationService: fachada de alto nivel, dos responsabilidades explícitas.

`core/Realtime/Notifications/NotificationService.php`:

```php
public function sendToUser(int|string $userId, string $type, array $payload = [], array $options = []): array
//   persiste (database) → emite (realtime) → devuelve el registro creado
```

`$payload` acepta `title`, `message`, `data` (array libre) y los fusiona con `type`. El frame WebSocket resultante es **exactamente** la forma del spec §7:

```json
{ "event": "notification", "data": { "id":"notif_…", "type":"ticket.created", "title":"…", "message":"…", "data":{…}, "created_at":"2026-09-11T20:00:00Z" } }
```

El `RealtimeChannel` se actualiza para emitir esa forma (evento `notification`, data con id/type/title/message/data/created_at). El test `NotificationManagerTest` espera hoy `event === 'notification.received'`; ese único assert se ajusta al nuevo nombre (`notification`) — cambio intencional, documentado en el handoff.

### D4 (revisado) — Entrega app→servidor: **polling server-side** sobre la tabla `notifications` (cross-platform, sin IPC).

> **Revisión durante implementación (WS-02):** el diseño original proponía un `HttpEventBus` que publica vía HTTP a un endpoint interno del servidor Workerman. Esto requiere un segundo `Worker` (HTTP) en el mismo archivo — no soportado en Windows (`multi workers init in one php file are not support`). Un segundo proceso con `proc_open` añade complejidad de ciclo de vida y no comparte memoria con el WS, por lo que no puede entregar a las conexiones. En su lugar, el **servidor WebSocket hace polling de la tabla `notifications`**: en cada tick consulta las notificaciones no entregadas a los usuarios con conexiones autenticadas y las envía vía `ConnectionManager::sendToUser()`. La latencia es `WEBSOCKET_POLL_INTERVAL` (default 1s). Esto:
> - Elimina el transporte cross-process (la BD es la cola).
> - Funciona idéntico en Windows y Linux.
> - Satisface §12: persistencia separada de la entrega; §13: el usuario offline recibe las notificaciones al reconectar (el high-water-mark se persiste en `runtime/realtime-delivery.json`).
> - No introduce dependencias.
>
> El `HttpEventBus` queda **fuera de v1**. La abstracción `EventBus` (LocalEventBus/RedisEventBus) se mantiene para los canales `public/private/presence` y para futura multi-instancia con Redis.

### D5 — `RealtimeManager` mode-aware (sin firma cambiada para tests).

```php
public function __construct(array $config, ?callable $redisFactory = null, bool $inProcess = true)
```

- `inProcess=true` (tests, CLI, server-side): bus = `LocalEventBus`/`RedisEventBus` (idéntico a hoy → 0 cambios en los 9 tests Realtime).
- `inProcess=false` (provider app-side): bus = `HttpEventBus` (local) o `RedisEventBus` (redis). El health-check de Redis se mantiene igual.

`RealtimeServiceProvider` (app) pasa `inProcess=false`. `RealtimeTestCommand` y el bootstrap de `websocket/server.php` construyen su propio `RealtimeManager` con `inProcess=true` (servidor-side, no contaminado por el container que ya se inicializó para HTTP).

### D6 — Variables de entorno: alias WEBSOCKET_* y REALTIME_*.

`config/realtime.php` resuelve primero `WEBSOCKET_*` y, si están vacíos, cae a `REALTIME_*` (compat). Esto preserva los entornos existentes que sólo definen `REALTIME_*` y a la vez adopta los nombres del spec del usuario (§4). `.env.example` documenta ambos grupos.

### D7 (revisado) — Integración REST: guía en docs, NO una app del framework.

> **Revisión durante refinamiento (WS-09):** el diseño original (D7) proponía una app `apps/Realtime` registrada por defecto con prefijo `v1` y rutas REST concretas. Esto acoplaba el framework a un conjunto fijo de endpoints y a un prefijo arbitrario. **Refinamiento**: el módulo de tiempo real pertenece al **core** (`core/Realtime/`). El framework **no incluye** una app REST de notificaciones pre-registrada (a diferencia de `apps/ApolloAuth` o `apps/Users`). Cada proyecto crea sus propios endpoints HTTP en una de sus apps, siguiendo la **guía de integración** documentada en `docs/websockets.md §13`.

**Implicaciones**:
- `config/apps.php` **no** incluye `'Realtime'` por defecto.
- `apps/Realtime/` **no** existe en el repositorio (eliminado en WS-09).
- Los servicios core (`NotificationService`, `MySqlNotificationRepository`, `ChannelAuthenticator`, `RealtimeManager`) se consumen desde cualquier app del proyecto del usuario.
- El prefijo de rutas (`v1`, `api/notifications`, etc.) es decisión del proyecto integrador, no del framework.
- Los tests del core siguen cubriendo la lógica de notificaciones y conexiones (sin app REST).

### D8 — `realtime:*` CLI y `composer websocket` (en el core).

- `realtime:start` → bootstrap app + `WorkermanServer::run()` (bloqueante). Reconoce `start -d` (Linux) y `start` (Windows foreground).
- `realtime:stop` → mata el PID (taskkill en Windows, posix en Linux); limpia `runtime/realtime.pid`.
- `realtime:restart` → stop + relanza (`proc_open` o background). En Windows usa `start /B` para no bloquear.
- `realtime:status` → lee pid file, muestra conexiones y driver.
- `realtime:test` → chequea PHP, Workerman, Redis, BD, secret, sin requerir `ext-openswoole`.
- `composer.json` añade `scripts.websocket = "php apollo realtime:start"`.

Los comandos viven en el core (`core/Console/Commands/Realtime*Command.php`); son invocables desde cualquier proyecto sin apps adicionales.

## Consecuencias

- **Tests existentes**: 8 tests Realtime verdes; 1 ajuste inevitable en `NotificationManagerTest::test_send_dispatches_database_and_realtime` (evento `notification.received` → `notification`, documentado).
- **Carga de bootstrap**: el servidor Workerman corre `new Application(...)` una vez al inicio (mismo bootstrap que el HTTP); `AuthService` requiere BD disponible → en la primera conexión se instancia la conexión PDO, igual que en una request HTTP.
- **Windows**: `Worker::count` se fuerza a 1 (Workerman lo detecta solo y emite el `WARN` "count unsupported on Windows"); `-d` daemon no funciona (esperado); `status`/`stop`/`restart` usan pid file + taskkill.
- **Multi-instancia**: queda como paso 2; `docs/websockets.md` lo describe con `workerman/channel` y un segundo Worker `redis-consumer` (que también requiere dos archivos en Windows).
- **API de cliente**: el `realtime.js` se mantiene 100% compatible con la versión actual (canal/presence siguen) y añade `token`, `on/emit/off` y manejo del frame `{event, data}`.

## Validación

- G4: `composer test` verde (suite completa, sin regresiones).
- G5: review de seguridad (auth, secretos, CWE-79/22/285/330/352). Lista de comprobación:
  - `user_id` nunca del cliente.
  - `hash_equals` para comparar `app_secret` y tickets.
  - Límite `WEBSOCKET_MAX_MESSAGE_SIZE` aplicado antes de decodificar JSON.
  - Cierre de conexión ante JWT inválido, JSON malformado, mensaje demasiado grande, tipo no soportado.
  - Logs no registran tokens ni `app_secret` (sólo IDs de conexión y canales).
  - WSS con Nginx; el servidor WS queda en `127.0.0.1` por default (no expuesto).
  - `WEBSOCKET_ENABLED=false` en el provider desactiva el binding del manager (parada limpia, no rompe el resto del framework).
