# Módulo WebSockets (Workerman)

WebSockets para notificaciones en tiempo real sobre **Workerman** (paquete Composer, sin extensión PHP nativa). Reemplaza la dependencia previa de OpenSwoole. El módulo vive en el **core** del framework (`core/Realtime/`); este doc explica cómo usarlo desde tu app.

Inspirado en Laravel Reverb / Pusher, pero con cero extensiones y compatible con Windows + Linux.

---

## Índice

1. [Instalación](#1-instalación)
2. [Configuración (variables de entorno)](#2-configuración-variables-de-entorno)
3. [Variables de entorno](#3-variables-de-entorno)
4. [Iniciar el servidor](#4-iniciar-el-servidor)
5. [Autenticación](#5-autenticación)
6. [Crear notificaciones](#6-crear-notificaciones)
7. [Consumir WebSocket desde el frontend (SDK)](#7-consumir-websocket-desde-el-frontend-sdk)
8. [Reconexión y heartbeat](#8-reconexión-y-heartbeat)
9. [Desarrollo local](#9-desarrollo-local)
10. [Producción](#10-producción)
11. [Nginx + WSS](#11-nginx--wss)
12. [Redis (multi-instancia)](#12-redis-multi-instancia)
13. [**Guía de integración REST de notificaciones**](#13-guía-de-integración-rest-de-notificaciones)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Instalación

```bash
composer install
```

`workerman/workerman` (^5) ya está en `require` de `composer.json`. No requiere extensiones nativas (es PHP puro). En Windows corre en single-process; en Linux puede multi-procesar con `count > 1`.

> **Sin `ext-openswoole`**: la versión previa del módulo la requería y limitaba Windows. Workerman no necesita ninguna extensión.

Verificar la instalación:

```bash
php apollo realtime:test
```

Salida esperada:

```
Realtime System
----------------
PHP            ✓  8.4.14
Workerman      ✓  v5.2.2  (paquete Composer)
Redis          ·  127.0.0.1:6379
Database       ✓  conectable
App secret     ✓  configurado
SSL            ✓  WS (terminar TLS en Nginx)
Poll interval  1s
```

---

## 2. Configuración (variables de entorno)

Las variables se resuelven con prioridad `WEBSOCKET_*` y fallback a `REALTIME_*` (legacy). Puedes usar cualquiera de los dos nombres; los nuevos (`WEBSOCKET_*`) son los recomendados.

`config/realtime.php` se encarga de la resolución.

---

## 3. Variables de entorno

```env
# --- Habilitar el módulo ---
WEBSOCKET_ENABLED=true

# --- Servidor ---
WEBSOCKET_HOST=127.0.0.1
WEBSOCKET_PORT=8080
WEBSOCKET_PUBLIC_URL=                # wss://midominio.com/ws en producción
WEBSOCKET_MAX_CONNECTIONS=10000
WEBSOCKET_MAX_MESSAGE_SIZE=8192

# --- Polling server-side (entrega de notificaciones) ---
WEBSOCKET_POLL_INTERVAL=1            # segundos

# --- Heartbeat ---
WEBSOCKET_HEARTBEAT_INTERVAL=30      # segundos
WEBSOCKET_CONNECTION_TIMEOUT=60      # segundos sin actividad → sweep

# --- Identidad de la aplicación ---
WEBSOCKET_APP_KEY=app_apollo         # público (cliente lo ve)
WEBSOCKET_APP_SECRET=                # OBLIGATORIO para canales privados (HMAC); recomendado también para que tu REST lo use como Bearer de servicio

# --- WSS directo (si no usas Nginx como terminación TLS) ---
WEBSOCKET_SSL_ENABLED=false
WEBSOCKET_SSL_LOCAL_CERT=
WEBSOCKET_SSL_LOCAL_PKEY=

# --- Redis (opcional; el health check decide el driver) ---
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

**Driver del bus (`REALTIME_DRIVER`)**:
- `auto` (default): ping a Redis; si responde → `redis`, si no → `local`.
- `local`: una sola instancia (el bus local en proceso). En `app` mode se materializa como `LocalEventBus` (la entrega real va por polling de BD).
- `redis`: exige Redis. (Multi-instancia: ver §12.)

---

## 4. Iniciar el servidor

```bash
# Foreground
php apollo realtime:start

# Foreground con dev script
composer websocket

# Daemon (Linux)
php apollo realtime:start -d
# o
composer websocket:start-d

# Detener
php apollo realtime:stop            # o composer websocket:stop

# Reiniciar
php apollo realtime:restart         # o composer websocket:restart

# Estado
php apollo realtime:status          # o composer websocket:status

# Health check
php apollo realtime:test            # o composer websocket:test
```

El servidor escribe su pid en `runtime/realtime.pid` (gestionado por el CLI, cross-platform). Los logs van a `runtime/workerman.log` (gestionado por Workerman).

**Standalone** (sin el CLI `apollo`):

```bash
php websocket/server.php start
php websocket/server.php start -d    # Linux
php websocket/server.php stop
php websocket/server.php status
```

---

## 5. Autenticación

La conexión WebSocket se autentica en el **handshake** (no después). El servidor determina el `user_id` — el cliente **nunca** lo declara.

**Token JWT** (recomendado para browsers):

```js
const socket = new RealtimeClient({
    url: 'ws://localhost:8080',
    token: jwt,                    // del endpoint de login de tu app
});
```

El navegador añade `?token=<jwt>` a la URL del handshake (no puede fijar `Authorization` en WS). El servidor valida con `AuthService::authenticateFromToken` (JWT + tabla `user_sessions`).

**Cabecera `Authorization: Bearer …`** (clientes Node/CLI):

```js
// node/wscat: wscat -c "ws://localhost:8080" -H "Authorization: Bearer <jwt>"
```

El servidor acepta ambas formas. Si no hay token o es inválido, la conexión se cierra con `{"type":"error","code":"AUTH_FAILED"}`.

**Canales privados/presence**: el cliente solicita un ticket firmado al backend:

```js
const socket = new RealtimeClient({
    url: 'ws://localhost:8080',
    token: jwt,
    auth: async (channel) => {
        const res = await fetch('/api/realtime/auth', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + jwt,
            },
            body: JSON.stringify({ channel }),
        });
        return res.json(); // { channel, auth, user_id }
    },
});
```

> **Nota**: el endpoint `/api/realtime/auth` no existe en el framework — debes crearlo en tu app (ver §13). El SDK JS solo lo invoca cuando hay canales privados/presence.

---

## 6. Crear notificaciones

### Opción A — `NotificationService` (recomendado)

API de alto nivel del core, desacoplada del transporte:

```php
use Apollo\Core\Realtime\Notifications\NotificationService;

$service = app(NotificationService::class);
$record = $service->sendToUser(
    7,                          // user_id destinatario
    'ticket.created',           // tipo semántico
    [
        'title' => 'Nuevo ticket',
        'message' => 'Se creó el ticket #123',
        'data' => ['ticket_id' => 123],
    ]
);

// $record = [
//   'id' => 'notif_…',
//   'type' => 'ticket.created',
//   'title' => '…', 'message' => '…', 'data' => […],
//   'created_at' => '2026-09-11T…',
// ]
```

Persiste en la tabla `notifications` (migración 009). El servidor Workerman hace polling de la tabla y entrega vía WebSocket a todas las conexiones del `user_id`. Si el servidor está caído, la notificación queda persistida y se entrega al reconectar (el polling usa high-water-mark por usuario en `runtime/realtime-delivery.json`).

**Varios usuarios**:

```php
$records = $service->sendToUsers([7, 8, 9], 'announcement', [
    'title' => 'Mantenimiento programado',
    'message' => '…',
    'data' => [],
]);
```

### Opción B — `Notification::send` (estilo DRF/Laravel)

```php
use Apollo\Core\Realtime\Notifications\Notification;

class TicketCreatedNotification extends Notification
{
    public function __construct(private int $ticketId) {}

    public function title(): string   { return 'Nuevo ticket'; }
    public function message(): string { return "Se creó el ticket #{$this->ticketId}"; }
    public function channels(): array { return ['database', 'realtime']; }
    public function data(): array     { return ['ticket_id' => $this->ticketId]; }
}

Notification::send(7, new TicketCreatedNotification(123));
```

### Dónde llamar

`NotificationService::sendToUser` se puede invocar desde **cualquier** punto de tu aplicación: un Controller HTTP, un comando CLI, un cron, un webhook entrante, una transición de dominio. La lógica de negocio no se acopla al transporte WebSocket.

---

## 7. Consumir WebSocket desde el frontend (SDK)

El SDK está en `public/js/realtime.js`. Uso básico:

```html
<script src="/js/realtime.js"></script>
<script>
    const socket = new RealtimeClient({
        url: 'ws://localhost:8080',
        token: window.API_TOKEN,    // JWT del usuario
    });

    // Top-level (spec §9)
    socket.on('notification', (data) => {
        console.log('🔔 notificación:', data);
        // data = { id, type, title, message, data, created_at }
    });

    socket.on('error', (err) => {
        console.warn('WS error', err);
    });

    // Para desconectar:
    // socket.disconnect();
</script>
```

Auto-conexión (data-attributes en el HTML):

```html
<div data-realtime-url="wss://midominio.com/ws"
     data-realtime-key="app_apollo"
     data-realtime-token="<jwt>"></div>
```

El SDK expone `on/emit/off/send/connect/disconnect/channel().listen()` y mantiene la reconexión con backoff y heartbeat.

---

## 8. Reconexión y heartbeat

- **Reconexión** con backoff exponencial: 1s → 2s → 4s → 8s → 16s → 30s (máximo). Hasta 10 intentos por defecto.
- **Heartbeat** cliente: `ping` cada 15s (configurable vía `heartbeatIntervalMs`).
- **Heartbeat servidor** (`WEBSOCKET_HEARTBEAT_INTERVAL`, default 30s): barre conexiones inactivas (sin tráfico en `WEBSOCKET_CONNECTION_TIMEOUT`, default 60s) y las desconecta limpiamente.

---

## 9. Desarrollo local

```bash
# 1) BD (SQLite en memoria o archivo)
cp .env.example .env
# Edita .env: DB_CONNECTION=sqlite, DB_DATABASE=:memory:

# 2) Instalar y migrar
composer install
php setup_database.php

# 3) Sembrar (opcional)
php run_seeders.php

# 4) Iniciar API HTTP
composer start                       # php -S localhost:8000 -t public

# 5) Iniciar WebSocket (otra terminal)
composer websocket                  # php apollo realtime:start

# 6) Health check
php apollo realtime:test
```

En Windows, `start -d` se ignora y el servidor corre en foreground. `Ctrl+C` lo detiene, o en otra terminal `php apollo realtime:stop`.

---

## 10. Producción

```bash
# 1) Variables de entorno en producción
APP_DEBUG=false
APP_ENV=production
WEBSOCKET_HOST=127.0.0.1
WEBSOCKET_PORT=8080
JWT_SECRET_KEY=<secreto fuerte>
WEBSOCKET_APP_SECRET=<otro secreto fuerte>
WEBSOCKET_PUBLIC_URL=wss://midominio.com/ws

# 2) Iniciar el servidor (Linux)
php apollo realtime:start -d

# 3) Proceso robusto
# - systemd unit (recomendado)
# - supervisor
# - Dokploy/Plesk process manager
```

**systemd unit** de ejemplo (`/etc/systemd/system/apollo-websocket.service`):

```ini
[Unit]
Description=Apollo WebSocket (Workerman)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/apollo
ExecStart=/usr/bin/php apollo realtime:start
Restart=always
RestartSec=5
StandardOutput=append:/var/log/apollo/websocket.log
StandardError=append:/var/log/apollo/websocket.err

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now apollo-websocket
```

---

## 11. Nginx + WSS

Nginx hace de terminador TLS y proxy al servidor Workerman en `127.0.0.1:8080`:

```nginx
server {
    listen 443 ssl http2;
    server_name midominio.com;

    ssl_certificate     /etc/letsencrypt/live/midominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/midominio.com/privkey.pem;

    # API REST de tu app
    location / {
        proxy_pass         http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }

    # WebSocket (WSS)
    location /ws {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;

        proxy_set_header   Upgrade           $http_upgrade;
        proxy_set_header   Connection        "Upgrade";
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;

        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }
}
```

Cliente: `wss://midominio.com/ws`. Workerman escucha en `0.0.0.0:8080` por default (cambia a `127.0.0.1` en prod para que solo Nginx lo alcance).

Si prefieres WSS directo sin Nginx, activa `WEBSOCKET_SSL_ENABLED=true` + `WEBSOCKET_SSL_LOCAL_CERT` + `WEBSOCKET_SSL_LOCAL_PKEY`.

---

## 12. Redis (multi-instancia)

**Estado actual (v1)**: la entrega de notificaciones a usuarios se hace por **polling server-side** de la tabla `notifications`. Esto es single-instance y no requiere Redis.

**Multi-instancia (paso 2)**: para escalar horizontalmente, el patrón objetivo es:

```
              ┌──────────────────┐
              │      Redis        │
              │  Pub/Sub + keys   │
              └────────┬─────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
    ┌───────▼──────┐      ┌───────▼──────┐
    │ Workerman 1  │      │ Workerman 2  │
    │ (WS server)  │      │ (WS server)  │
    └───────┬──────┘      └───────┬──────┘
            │                     │
        Clientes              Clientes
```

Pasos:
1. `composer require workerman/channel` (componente oficial de IPC para Workerman).
2. Definir un `Channel\Server` en el mismo proceso (Linux) o en proceso aparte (Windows).
3. Cada worker WebSocket suscribe vía `Channel\Client::connect(...)` y, cuando llega un mensaje, lo entrega localmente.
4. La app publica con `RedisEventBus` (ya existe en `core/Realtime/Bus/RedisEventBus.php`).

**v1 no implementa este paso** porque (a) la abstracción `EventBus` ya está preparada, (b) el polling cubre single-instance, (c) la complejidad y dependencias extra no se justifican hasta que haya más de un servidor.

---

## 13. Guía de integración REST de notificaciones

> **Importante**: el framework **no incluye** una app REST de notificaciones pre-registrada (a diferencia de `apps/ApolloAuth` o `apps/Users`). El core provee la lógica (`NotificationService`, `NotificationRepository`, `ChannelAuthenticator`); los endpoints HTTP los creas **tú** en una de tus apps (`apps/Notifications`, `apps/<TuApp>`, etc.) siguiendo esta guía.

Esta guía es **orientativa**: adáptala al prefijo, naming y middleware de tu proyecto. El ejemplo usa prefijo `/api/notifications` y el middleware `auth` existente (`AuthMiddleware` de ApolloAuth).

### 13.1 Endpoints sugeridos

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| `GET`  | `/api/notifications` | JWT | Lista del usuario. `?unread=1` filtra no leídas. |
| `GET`  | `/api/notifications/{id}` | JWT | Una notificación. 404 si no existe, 403 si no es del usuario. |
| `POST` | `/api/notifications/{id}/read` | JWT | Marca como leída. 403 si no es del usuario. |
| `POST` | `/api/notifications/read-all` | JWT | Marca todas como leídas. |

(Opcional, para servicios con `app_secret`):

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| `POST` | `/api/realtime/auth` | JWT o `app_secret` | Firma un ticket HMAC para canales privados/presence. |
| `POST` | `/api/events` | `app_secret` | Publica un evento en un canal (uso interno). |

### 13.2 Crear la app

Crea una app nueva (o añade los endpoints a una existente):

```bash
php apollo make:app Notifications
```

Esto crea `apps/Notifications/` con `app.json`, `config/app.php`, `Routes/api.php`, `Controllers/NotificationsController.php`. Regístrala en `config/apps.php`:

```php
// config/apps.php
'registered' => [
    'ApolloAuth',
    'Users',
    'Products',
    'Notifications',  // ← añadido
],
```

### 13.3 Rutas (`apps/Notifications/Routes/api.php`)

```php
use Apps\Notifications\Controllers\NotificationsController;

/** @var \Apollo\Core\Router\Router $router */

// Notificaciones del usuario autenticado
$router->group(['middleware' => ['auth'], 'prefix' => 'api/notifications'], function ($router) {
    $router->get('/',          [NotificationsController::class, 'index'])->name('notifications.index');
    $router->get('/{id}',      [NotificationsController::class, 'show'])->name('notifications.show');
    $router->post('/{id}/read',[NotificationsController::class, 'markAsRead'])->name('notifications.read');
    $router->post('/read-all', [NotificationsController::class, 'markAllAsRead'])->name('notifications.read_all');
});
```

### 13.4 Controller (`apps/Notifications/Controllers/NotificationsController.php`)

```php
<?php

namespace Apps\Notifications\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;
use Apollo\Core\Realtime\Support\RealtimeManager;
use Apollo\Core\Realtime\Auth\ChannelAuthenticator;

class NotificationsController extends Controller
{
    private MySqlNotificationRepository $repo;

    public function __construct()
    {
        $this->repo = new MySqlNotificationRepository();
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->repo->forUser((int) $user->id, [
            'unread' => (bool) $request->query('unread', false),
        ]);

        return $this->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->repo->find($id);

        if (!$row) {
            return $this->json(['error' => 'Not Found'], 404);
        }

        // Autorización por dueño (evita IDOR)
        if ((int) ($row['user_id'] ?? 0) !== (int) $user->id) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return $this->json(['success' => true, 'data' => $row]);
    }

    public function markAsRead(Request $request, string $id): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->repo->find($id);

        if (!$row) {
            return $this->json(['error' => 'Not Found'], 404);
        }

        if ((int) ($row['user_id'] ?? 0) !== (int) $user->id) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->repo->markAsRead($id);

        return $this->json(['success' => true, 'id' => $id]);
    }

    public function markAllAsRead(Request $request): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $items = $this->repo->forUser((int) $user->id);
        $count = 0;

        foreach ($items as $row) {
            if (empty($row['read_at']) && $this->repo->markAsRead($row['id'])) {
                $count++;
            }
        }

        return $this->json(['success' => true, 'marked' => $count]);
    }
}
```

> **Notas**:
> - El repositorio concreto (`MySqlNotificationRepository`) funciona sobre MySQL o SQLite indistintamente.
> - `Request::user()` lo expone el middleware `auth` (ApolloAuth) tras validar el JWT.
> - Si quieres autorización de servicio con `app_secret` (para emitir `POST /api/realtime/auth`), añade un endpoint extra que valide el `Bearer` con `hash_equals($config->appSecret(), $token)`.

### 13.5 Generar una notificación desde tu lógica de negocio

Desde cualquier Controller, Service, Comando o cron:

```php
use Apollo\Core\Realtime\Notifications\NotificationService;

class TicketController extends Controller
{
    public function store(Request $request): Response
    {
        // ... tu lógica para crear el ticket ...

        $ticketId = 123;

        // Notificar al usuario asignado
        $service = app(NotificationService::class);
        $service->sendToUser(
            $assignedUserId,
            'ticket.created',
            [
                'title' => 'Nuevo ticket',
                'message' => "Se te asignó el ticket #{$ticketId}",
                'data' => ['ticket_id' => $ticketId],
            ]
        );

        return $this->json(['success' => true, 'id' => $ticketId], 201);
    }
}
```

Si el servidor WebSocket está corriendo, el destinatario recibe la notificación en ~1s (intervalo de polling). Si está caído, queda persistida y se entrega al reconectar.

---

## 14. Troubleshooting

### "Workerman no instalado" en `realtime:test`

```bash
composer require workerman/workerman
```

### El servidor arranca pero el cliente no se conecta

- `WEBSOCKET_ENABLED=true` en `.env`.
- `WEBSOCKET_HOST=127.0.0.1` y `WEBSOCKET_PORT=8080` (o el que uses).
- El token JWT es válido y vigente. `realtime:test` valida BD y secreto pero no el token concreto.
- Si usas Nginx + WSS, el proxy pasa los headers `Upgrade`/`Connection` (ver §11).

### `AUTH_FAILED` en el cliente

- Token JWT inválido, expirado o revocado.
- `JWT_SECRET_KEY` cambió (invalidó todos los tokens).
- La tabla `user_sessions` requiere que la sesión no esté revocada (`is_revoked=false`) y no expirada (`expires_at > now()`).

### `Database ✗ could not find driver`

Habilita la extensión de PDO correspondiente en `php.ini`:
- MySQL: `extension=pdo_mysql`
- SQLite: `extension=pdo_sqlite`

### `start -d` no daemoniza en Windows

Esperado. Workerman no soporta daemon en Windows (`-d` se ignora). Usa `Ctrl+C` o `realtime:stop`.

### El servidor se reinicia y se pierden notificaciones en vuelo

El high-water-mark por usuario se persiste en `runtime/realtime-delivery.json` (se actualiza tras cada entrega). Al reiniciar, el servidor relee este archivo y continúa desde el último id entregado. Si el archivo se borra, el servidor re-entregará todas las notificaciones existentes a los usuarios conectados (idempotente en el cliente si filtra duplicados por `id`).

### Mensaje "MESSAGE_TOO_LARGE"

El cliente envió un frame > `WEBSOCKET_MAX_MESSAGE_SIZE` (default 8 KB). El servidor cierra la conexión.

### `realtime:stop` no mata el proceso

- En Windows: `realtime:stop` usa `taskkill /F /T`. Si hay procesos huérfanos, ejecuta manualmente: `taskkill /F /IM php.exe`.
- En Linux: `posix_kill($pid, SIGTERM)`. Si no responde, `SIGKILL`.

### Cómo probar notificaciones localmente

```bash
# 1) Genera un JWT válido (login en la app)
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"secret"}' | jq -r .token)

# 2) Crear una notificación vía PHP (desde un Controller o un script)
php -r "
  require 'vendor/autoload.php';
  \$app = new Apollo\Core\Application(__DIR__);
  \$app->make('config');
  foreach (\$app->make('config')->get('providers.core', []) as \$p) {
      if (class_exists(\$p)) \$app->registerServiceProvider(new \$p(\$app));
  }
  \$app->bootServiceProviders();
  \$svc = new Apollo\Core\Realtime\Notifications\NotificationService(new Apollo\Core\Realtime\Notifications\MySqlNotificationRepository());
  var_dump(\$svc->sendToUser(1, 'ticket.created', ['title'=>'Hola','message'=>'…','data'=>['ticket_id'=>1]]));
"

# 3) Conectar un cliente WebSocket con el JWT y observa la entrega
node -e "
  const WS = require('ws');
  const ws = new WS('ws://localhost:8080?token=' + process.env.TOKEN);
  ws.on('open', () => console.log('open'));
  ws.on('message', (m) => console.log('msg:', m.toString()));
"
```

---

## Resumen de cambios respecto a la versión OpenSwoole

| Antes | Ahora |
|---|---|
| Extensión `ext-openswoole` (no Windows) | Paquete `workerman/workerman` (Windows + Linux) |
| `composer suggest: ext-openswoole` | Eliminado |
| `php apollo realtime:start` requiere OpenSwoole | Funciona en Windows y Linux |
| `docs/realtime.md` menciona OpenSwoole | Actualizado a Workerman |
| Transporte cross-process via bus local (no funcional) | Polling server-side de `notifications` (D4-revisado) |
| `HttpEventBus` (no implementado en v1) | Diferido; cubierto por polling |
| Frame realtime: `event: notification.received` | `event: notification` (spec §7) |
| `Connection::send` → `void` | `bool` (para que `sendToUser` cuente entregas) |
| `apps/Realtime` como app del framework | **Eliminado**: el WS vive en el core; cada proyecto expone su propia REST (guía §13) |
| SDK JS sin token en handshake | `token` en query + API top-level `on/emit/off` |
