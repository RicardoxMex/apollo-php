# Spec — WebSockets / Workerman

> Fuente de verdad de requisitos para este trabajo. Formato canónico: `templates/requirements.md` (`.ai/templates/requirements.md`). Flujo: `workflows/spec-driven-development.md`.

## Contexto

Apollo Framework expone un módulo `core/Realtime/` opcional para WebSockets, canales y notificaciones (commit `d437b9f`). El servidor actual requiere la extensión PHP `ext-openswoole`, que **no se puede instalar en Windows** y limita la portabilidad. La máquina de desarrollo del usuario es `win32` y la suite de tests (125 verdes) corre sin esa extensión. El objetivo es migrar el servidor a **Workerman** (paquete Composer, sin extensión, soporta Windows en modo single-process) manteniendo intactos: la API de la fachada `Realtime::*`, el modelo de canales (públicos / privados con ticket HMAC / presence), el `NotificationManager` con canales `database` y `realtime`, el repositorio sobre migración `009_create_notifications_table`, y la app REST opcional `apps/Realtime`. Se añade además una API de alto nivel `NotificationService::sendToUser($userId, $type, $payload)` desacoplada del transporte WebSocket, y autenticación JWT a nivel de conexión (el servidor determina el `user_id`, no el cliente).

## Requisitos

### Funcionales

1. **Servidor WebSocket Workerman** reemplazando `core/Realtime/WebSocket/WebSocketServer.php` (basado en OpenSwoole). Escucha en `WEBSOCKET_HOST:WEBSOCKET_PORT` (default `127.0.0.1:8080`); defaults coherentes con la configuración actual.
2. **Autenticación de conexión con JWT**: la conexión WebSocket extrae el token del query `?token=<jwt>` (los navegadores no permiten `Authorization` en el handshake) o, cuando el cliente lo envíe, de la cabecera `Authorization: Bearer …`. Se valida con `AuthService::authenticateFromToken()` (mecanismo existente: JWT + `user_sessions`). El servidor asigna `user_id` a la conexión; el cliente nunca lo declara.
3. **ConnectionManager** extendido con `sendToUser($userId, $payload)` y `getConnectionsForUser($userId)` (mantener `connectionsForUser()` por compatibilidad). Una notificación llega a **todas** las conexiones autenticadas del usuario objetivo.
4. **NotificationService** (`core/Realtime/Notifications/NotificationService.php`) con `sendToUser(int|string $userId, string $type, array $payload): array` que persiste en BD y emite por el canal `realtime`. API de alto nivel desacoplada del transporte.
5. **Transporte app → servidor (v1)**: la app PHP no comparte proceso con el servidor WebSocket. El servidor hace **polling server-side** de la tabla `notifications` (default cada 1s) y entrega vía `ConnectionManager::sendToUser()`. No requiere IPC, segundo puerto ni segundo proceso; funciona idéntico en Windows y Linux. El bus Redis (`RedisEventBus`) queda intacto como vía futura de multi-instancia (especificación §17). El `HttpEventBus` queda diferido.
6. **Protocolo de mensajes** estable (spec §7): todo frame WebSocket es JSON. Tipos: `{"type":"connected","connection_id":"…"}`, `{"type":"pong"}`, `{"type":"error","code":"…"}`, y eventos `{"event":"<name>","data":{…}}` o `{"type":"event","channel":"…","event":"…","data":{…}}` para compatibilidad con el SDK channel-based existente.
7. **Notificaciones en el wire**: `{"event":"notification","data":{"id","type","title","message","data","created_at"}}` — el cliente hace `socket.on('notification', cb)`.
8. **Heartbeat** ping/pong: timer periódico que envía ping a todas las conexiones y barre las que excedan `WEBSOCKET_CONNECTION_TIMEOUT` (reutiliza `core/Realtime/WebSocket/Heartbeat`).
9. **SDK JS** (`public/js/realtime.js`) extendido: envía `?token=<jwt>` en el handshake, expone `on/emit/off` para eventos globales (`socket.on('notification', cb)`), conserva reconexión con backoff exponencial (1s→2s→4s→…→30s) y heartbeat. Mantiene la API de `channel(name).listen(event, cb)` por compatibilidad.
10. **Persistencia separada de la entrega**: `NotificationService` siempre persiste en `notifications` (migración 009) aunque el servidor WebSocket esté caído; si la entrega en vivo falla, el cliente recupera vía los endpoints REST de tu app.
11. **Integración REST opcional**: el framework **no incluye** una app REST de notificaciones pre-registrada. El core provee la lógica (`NotificationService`, `NotificationRepository`, `ChannelAuthenticator`); cada proyecto crea sus propios endpoints HTTP en una de sus apps (guía en `docs/websockets.md §13`). Ejemplo de endpoints sugeridos: `GET /api/notifications` (JWT), `GET /api/notifications/{id}` (JWT + autorización por dueño), `POST /api/notifications/{id}/read` (JWT), `POST /api/notifications/read-all` (JWT).
12. **CLI** `php apollo realtime:{start,stop,restart,status,test}` funcionando en Windows (sin `-d` daemon) y en Linux (con `-d` daemon, stop con SIGINT, status con pid file). `realtime:test` reporta PHP, Workerman, Redis, BD, app_secret; ya no requiere `ext-openswoole`.
13. **Composer script**: `composer websocket` que invoca `php apollo realtime:start`; documentado.
14. **Producción con WSS** vía Nginx: docs con bloque `location /ws { proxy_pass http://127.0.0.1:8080; proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade"; … }`.
15. **Redis**: el `RedisEventBus` existente se conserva como vía futura multi-instancia; el servidor v1 single-instance consume vía `HttpEventBus`. Documentado en `docs/websockets.md` con la arquitectura objetivo y los pasos para activarla.
16. **Logs útiles** (servidor): conexión, autenticación, entrega, errores; nunca se registran tokens, cookies ni payloads sensibles. Nivel y verbosidad configurables (`WEBSOCKET_LOG_LEVEL`).
17. **Manejo de errores**: una conexión defectuosa se cierra individualmente; el servidor no se cae. Errores de envío se registran sin propagarse.
18. **Variables de entorno** (alias de las `REALTIME_*` existentes, según convención del proyecto):
    ```
    WEBSOCKET_ENABLED=true
    WEBSOCKET_HOST=127.0.0.1
    WEBSOCKET_PORT=8080
    WEBSOCKET_PUBLIC_URL=          # wss://midominio.com/ws en producción
    WEBSOCKET_HEARTBEAT_INTERVAL=30
    WEBSOCKET_CONNECTION_TIMEOUT=60
    WEBSOCKET_MAX_MESSAGE_SIZE=8192
    WEBSOCKET_MAX_CONNECTIONS=10000
    WEBSOCKET_APP_KEY=app_apollo
    WEBSOCKET_APP_SECRET=          # obligatorio para canales privados
    WEBSOCKET_POLL_INTERVAL=1      # segundos; intervalo de polling server-side de notifications
    WEBSOCKET_SSL_ENABLED=false
    WEBSOCKET_SSL_LOCAL_CERT=
    WEBSOCKET_SSL_LOCAL_PKEY=
    ```

### No funcionales

- **Seguridad**: nunca confiar en `user_id` del cliente; validar tamaño de mensaje; sanitizar payloads; `hash_equals` para comparar secretos; cerrar conexiones inválidas; canales privados sin ticket HMAC válido → `CHANNEL_UNAUTHORIZED` y desconexión.
- **Mantenibilidad**: archivos nuevos ≤ ~300 líneas cuando sea posible; una sola responsabilidad por clase; nombres coherentes con la base existente.
- **Compatibilidad**: PHP ≥ 8.3 (la base ya exige 8.3, en esta máquina corre 8.4). La suite `composer test` (125 tests sin DB + 5 SQLite) sigue verde.
- **No regresiones**: `Realtime::broadcast()`, `Realtime::to()->emit()`, `Notification::send()`, `RealtimeChannel` (evento `notification`), `Broadcaster`, `ChannelManager`, `ConnectionManager`, `MessageHandler`, `ChannelAuthenticator`, `MySqlNotificationRepository`, `apps/Realtime` (rutas `events/broadcast/realtime.auth/channels/notifications`) deben seguir funcionando; tests existentes verdes con los ajustes inevitables del nombre de evento.

## Criterios de aceptación

- [ ] `composer install` instala `workerman/workerman ^5` y elimina la dependencia de `ext-openswoole` (sale de `suggest`).
- [ ] `php apollo realtime:start` arranca un servidor Workerman que acepta conexiones WebSocket en `WEBSOCKET_HOST:WEBSOCKET_PORT` y autentica vía JWT.
- [ ] Conexión sin token válido: aceptada como anónima, no puede suscribirse a canales privados, no recibe notificaciones de usuario.
- [ ] Conexión con token válido: el servidor conoce `user_id`; recibe notificaciones dirigidas a ese `user_id` en todas sus conexiones (Chrome + Firefox + Mobile).
- [ ] `NotificationService::sendToUser(7, 'ticket.created', ['title'=>'…','message'=>'…','data'=>['ticket_id'=>123]])` persiste en `notifications` y entrega el frame `{"event":"notification","data":{…}}` a las conexiones del usuario 7; si el servidor está caído, persiste igual.
- [ ] `php apollo realtime:test` muestra el estado real (PHP, Workerman, Redis, BD, secret) sin requerir `ext-openswoole`.
- [ ] `php apollo realtime:stop` y `realtime:restart` funcionan en Windows (taskkill) y Linux (SIGTERM).
- [ ] `composer test` mantiene el verde (incluidos los nuevos tests: `ConnectionManager::sendToUser`, `NotificationService`, `HttpEventBus`, `MessageHandler` con JWT, ajuste de `NotificationManagerTest` por cambio de event name).
- [ ] `docs/websockets.md` cubre los 14 puntos del spec §22; `docs/realtime.md` queda actualizado sin mencionar OpenSwoole; `docs/README.md` enlaza `websockets.md`.
- [ ] `config/realtime.php` y `.env.example` aceptan los nombres `WEBSOCKET_*` (con fallback a `REALTIME_*`).
- [ ] El core NO incluye una app REST de notificaciones. `docs/websockets.md §13` documenta una guía de integración completa (crear `apps/Notifications`, registrar en `config/apps.php`, controller con `MySqlNotificationRepository` + autorización por dueño, rutas con middleware `auth`).

## Fuera de alcance

- Multi-instancia con Redis (la abstracción `RedisEventBus` queda lista; el consumo server-side vía workerman/channel queda documentado como paso 2, no implementado en v1).
- Reemplazo del `Core\Auth` o de `JWTManager` (se reutilizan tal cual).
- Rate limiting específico del WebSocket (gap del framework, fuera de alcance).
- Frontend real: el proyecto es API-only; el SDK `public/js/realtime.js` se actualiza pero no se añade UI.
- WebRTC, video, push notifications nativas.

## Preguntas abiertas

- Ninguna — la spec está lista para planear. Decisiones detalladas en `design.md`.
