<?php

namespace Apollo\Core\Realtime\WebSocket;

use Apollo\Core\Application;
use Apollo\Core\Container\Container;
use Apollo\Core\Realtime\Auth\ChannelAuthenticator;
use Apollo\Core\Realtime\Auth\ConnectionAuthenticator;
use Apollo\Core\Realtime\Channels\ChannelManager;
use Apollo\Core\Realtime\Connections\Connection;
use Apollo\Core\Realtime\Connections\ConnectionManager;
use Apollo\Core\Realtime\Contracts\NotificationRepository;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;
use Apollo\Core\Realtime\Support\RealtimeConfig;
use Apollo\Core\Realtime\Support\RealtimeManager;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as HttpRequest;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Servidor WebSocket sobre Workerman (PHP >= 8.3, sin extensión nativa).
 *
 * Responsabilidades:
 *  - Escuchar conexiones WebSocket en host:port configurados.
 *  - Autenticar JWT en el handshake (onWebSocketConnect) vía ConnectionAuthenticator.
 *  - Despachar mensajes JSON al MessageHandler existente (canales public/private/presence).
 *  - Heartbeat: ping periódico + barrido de conexiones muertas (Heartbeat + ConnectionManager::sweep).
 *  - Polling de la tabla `notifications` (D4-revisado) para entrega cross-process a
 *    usuarios autenticados, con high-water-mark por usuario persistido en disco.
 *
 * Cross-platform:
 *  - Un único Worker (websocket://) por archivo → compatible con Windows
 *    (Workerman en Windows no permite múltiples Workers en un mismo archivo).
 *  - `-d` daemoniza solo en Linux; en Windows corre en foreground.
 *
 * Bootstrap de la aplicación (Application + providers + DB) se hace en `onWorkerStart`
 * para que cada worker (post-fork en Linux) inicialice su propio container y PDO.
 */
class WebSocketServer
{
    private string $basePath;
    private RealtimeConfig $config;

    /** Estado inicializado en onWorkerStart (tras bootstrap). */
    private ?ConnectionManager $connections = null;
    private ?MessageHandler $messageHandler = null;
    private ?ConnectionAuthenticator $authenticator = null;
    private ?RealtimeManager $realtime = null;
    private ?NotificationRepository $notifications = null;

    /** High-water-mark de notificaciones entregadas por usuario (id). */
    private array $lastDeliveredId = [];

    private string $deliveryStateFile;

    public function __construct(string $basePath, ?RealtimeConfig $config = null)
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->config = $config ?? new RealtimeConfig(
            is_file($this->basePath . '/config/realtime.php')
                ? (function () { return require $this->basePath . '/config/realtime.php'; })()
                : []
        );
        $this->deliveryStateFile = $this->basePath . '/runtime/realtime-delivery.json';
    }

    public function config(): RealtimeConfig
    {
        return $this->config;
    }

    /**
     * Arranca el servidor. Bloqueante: llama a Worker::runAll().
     */
    public function start(): void
    {
        $host = $this->config->host();
        $port = $this->config->port();

        $worker = new Worker("websocket://{$host}:{$port}");

        if ($this->config->sslEnabled() && $this->config->sslLocalCert() && $this->config->sslLocalKey()) {
            $worker->transport = 'ssl';
            $worker->context = [
                'ssl' => [
                    'local_cert' => $this->config->sslLocalCert(),
                    'local_key' => $this->config->sslLocalKey(),
                ],
            ];
        }

        // Cross-platform: 1 proceso en Windows, configurable en Linux.
        $worker->count = $this->workerCount();
        $worker->name = 'apollo-websocket';

        $server = $this;

        $worker->onWorkerStart = function (Worker $worker) use ($server) {
            $server->bootstrapWorker();
        };

        $worker->onWebSocketConnect = function (TcpConnection $connection, HttpRequest $request) use ($server) {
            $server->handleWebSocketConnect($connection, $request);
        };

        $worker->onMessage = function (TcpConnection $connection, $data) use ($server) {
            $server->handleMessage($connection, $data);
        };

        $worker->onClose = function (TcpConnection $connection) use ($server) {
            $server->handleClose($connection);
        };

        // Expone el server en la propiedad del Worker para que /internal/status (futuro)
        // o herramientas externas puedan acceder al estado si se necesitase.
        $worker->apolloServer = $server;

        $this->log("WebSocket server listening on {$this->scheme()}://{$host}:{$port} (workers: {$worker->count})");

        Worker::runAll();
    }

    /**
     * Bootstrap por-worker: crea Application, providers, managers, timers.
     */
    public function bootstrapWorker(): void
    {
        $this->ensureRuntimeDir();

        $app = new Application($this->basePath);
        $app->make('config');

        $providers = $app->make('config')->get('providers.core', []);
        foreach ($providers as $providerClass) {
            if (class_exists($providerClass)) {
                $app->registerServiceProvider(new $providerClass($app));
            }
        }
        $app->bootServiceProviders();

        // Apps registradas (ApolloAuth, Users, Products, Realtime, Uploads).
        // Cargarlas para que el container resuelva User/UserSession/AuthService.
        // En CLI (isConsoleMode=true) los error_log se silencian dentro de registerApp.
        foreach ($app->make('config')->get('apps.registered', []) as $appName) {
            try {
                $app->registerApp($appName);
            } catch (\Throwable $e) {
                $this->log("⚠️  App '{$appName}' no se pudo registrar: " . $e->getMessage());
            }
        }

        $this->connections = new ConnectionManager(new ChannelManager(), $this->config->maxConnections());
        $this->authenticator = new ConnectionAuthenticator();
        $this->messageHandler = new MessageHandler(
            $this->connections,
            new ChannelAuthenticator($this->config),
            $this->config
        );
        $this->realtime = new RealtimeManager(
            $app->make('config')->get('realtime', []),
            null,
            true  // server-side: in-process bus
        );

        try {
            $this->notifications = new MySqlNotificationRepository();
        } catch (\Throwable $e) {
            $this->log("⚠️  No se pudo inicializar el repositorio de notificaciones: " . $e->getMessage());
            $this->notifications = null;
        }

        $this->lastDeliveredId = $this->loadDeliveryState();

        // Timer: heartbeat (ping + sweep de conexiones muertas)
        $heartbeat = (int) max(1, $this->config->heartbeatInterval());
        Timer::add($heartbeat, function () {
            if ($this->connections === null) {
                return;
            }

            $timeout = (int) $this->config->connectionTimeout();

            // sweep de inactivas
            $removed = $this->connections->sweep($timeout);
            if ($removed > 0) {
                $this->log("Sweep: {$removed} conexiones eliminadas por inactividad");
            }
        });

        // Timer: polling de notificaciones (D4-revisado)
        $poll = (int) max(1, $this->config->pollInterval());
        Timer::add($poll, function () {
            $this->pollAndDeliverNotifications();
        });

        $this->log("Worker bootstrap OK (heartbeat {$heartbeat}s, poll {$poll}s)");
    }

    /**
     * Manejo del handshake WebSocket: autentica JWT, crea la Connection de dominio.
     */
    public function handleWebSocketConnect(TcpConnection $connection, HttpRequest $request): void
    {
        if ($this->authenticator === null) {
            $this->closeWithError($connection, 'Server not ready');
            return;
        }

        $userId = $this->authenticator->authenticate($request->get(), $request->header());

        if ($userId === null) {
            $this->closeWithError($connection, 'Unauthorized');
            return;
        }

        try {
            $domainConnection = $this->connections->connect($userId);
        } catch (\Throwable $e) {
            $this->closeWithError($connection, 'Server overloaded');
            return;
        }

        // Asocia la Connection de dominio con la conexión Workerman.
        $connection->domainConnection = $domainConnection;
        $connection->domainConnectionId = $domainConnection->id();

        // Wire del send: serializa el payload a JSON y lo envía como frame WS.
        $domainConnection->attachResource(function (array $payload) use ($connection) {
            try {
                $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });

        $domainConnection->send([
            'type' => 'connected',
            'connection_id' => $domainConnection->id(),
            'user_id' => $userId,
        ]);

        $this->log("Client connected: conn={$domainConnection->id()} user={$userId} fd={$connection->id}");
    }

    /**
     * Manejo de mensajes WebSocket: delega al MessageHandler existente.
     */
    public function handleMessage(TcpConnection $connection, $data): void
    {
        $domainConnection = $connection->domainConnection ?? null;

        if (!$domainConnection instanceof Connection) {
            $this->closeWithError($connection, 'Not connected');
            return;
        }

        $maxSize = (int) $this->config->maxMessageSize();
        if (strlen((string) $data) > $maxSize) {
            $domainConnection->send([
                'type' => 'error',
                'code' => 'MESSAGE_TOO_LARGE',
                'message' => "Mensaje excede el tamaño permitido ({$maxSize} bytes)",
            ]);
            $connection->close();
            return;
        }

        $decoded = json_decode((string) $data, true);

        if (!is_array($decoded)) {
            $domainConnection->send([
                'type' => 'error',
                'code' => 'INVALID_JSON',
                'message' => 'JSON inválido',
            ]);
            return;
        }

        try {
            foreach ($this->messageHandler->handle($domainConnection, $decoded) as $result) {
                if (isset($result['broadcast'])) {
                    $this->connections->channels()->broadcast(
                        $result['broadcast']['channel'],
                        $result['broadcast']['payload']
                    );
                    continue;
                }

                if (isset($result['payload'])) {
                    $domainConnection->send($result['payload']);
                }
            }
        } catch (\Throwable $e) {
            $this->log("Error procesando mensaje: " . $e->getMessage());
            $domainConnection->send([
                'type' => 'error',
                'code' => 'INTERNAL_ERROR',
                'message' => 'Error procesando el mensaje',
            ]);
        }
    }

    /**
     * Manejo del cierre: desconecta la Connection de dominio.
     */
    public function handleClose(TcpConnection $connection): void
    {
        $domainConnection = $connection->domainConnection ?? null;
        if ($domainConnection instanceof Connection) {
            $this->connections->disconnect($domainConnection->id());
            $this->log("Client disconnected: conn={$domainConnection->id()} user={$domainConnection->userId()}");
        }
    }

    /**
     * Polling de notificaciones: entrega a usuarios autenticados las nuevas
     * notificaciones (id > lastDeliveredId) vía sendToUser, y actualiza el HWM.
     */
    public function pollAndDeliverNotifications(): void
    {
        if ($this->connections === null || $this->notifications === null) {
            return;
        }

        $userIds = $this->connections->getUserIds();
        if (empty($userIds)) {
            return;
        }

        $dirty = false;

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $afterId = $this->lastDeliveredId[$userId] ?? '';

            try {
                $rows = $this->notifications->forUserAfterId($userId, $afterId, 100);
            } catch (\Throwable $e) {
                $this->log("⚠️  poll: error leyendo notifications para user={$userId}: " . $e->getMessage());
                continue;
            }

            if (empty($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $payload = [
                    'event' => 'notification',
                    'data' => [
                        'id' => $row['id'],
                        'type' => $row['type'] ?? 'notification',
                        'title' => $row['title'] ?? '',
                        'message' => $row['message'] ?? '',
                        'data' => $row['data'] ?? [],
                        'created_at' => $row['created_at'] ?? null,
                    ],
                ];

                $delivered = $this->connections->sendToUser($userId, $payload);
                $this->log("Notification {$row['id']} → user={$userId} delivered={$delivered}");

                $this->lastDeliveredId[$userId] = $row['id'];
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->saveDeliveryState();
        }
    }

    /**
     * Estado del servidor (para diagnóstico / realtime:status).
     *
     * @return array{driver:string,host:string,port:int,connections:int,users:int,poll_interval:int}
     */
    public function status(): array
    {
        return [
            'driver' => $this->realtime ? $this->realtime->driver() : 'n/a',
            'host' => $this->config->host(),
            'port' => $this->config->port(),
            'connections' => $this->connections ? $this->connections->countConnections() : 0,
            'users' => $this->connections ? count($this->connections->getUserIds()) : 0,
            'poll_interval' => $this->config->pollInterval(),
            'heartbeat_interval' => $this->config->heartbeatInterval(),
        ];
    }

    private function workerCount(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 1; // Workerman en Windows: count no soportado
        }
        $env = getenv('WEBSOCKET_WORKERS');
        $count = $env !== false ? (int) $env : 1;
        return $count < 1 ? 1 : $count;
    }

    private function scheme(): string
    {
        return $this->config->sslEnabled() ? 'wss' : 'ws';
    }

    private function ensureRuntimeDir(): void
    {
        $dir = $this->basePath . '/runtime';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function loadDeliveryState(): array
    {
        if (!is_file($this->deliveryStateFile)) {
            return [];
        }

        $raw = @file_get_contents($this->deliveryStateFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function saveDeliveryState(): void
    {
        $this->ensureRuntimeDir();
        @file_put_contents(
            $this->deliveryStateFile,
            json_encode($this->lastDeliveredId, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function closeWithError(TcpConnection $connection, string $message): void
    {
        try {
            $connection->send(json_encode([
                'type' => 'error',
                'code' => 'AUTH_FAILED',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // ignore
        }

        // Cierre diferido (Workerman envía el frame antes de cerrar)
        Timer::add(0.01, function () use ($connection) {
            try { $connection->close(); } catch (\Throwable $e) {}
        });
    }

    private function log(string $message): void
    {
        // Workerman redirige stdout a su log en daemon mode; en foreground va a stdout.
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    }
}
