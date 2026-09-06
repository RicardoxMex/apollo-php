<?php

namespace Apollo\Core\Realtime\WebSocket;

use Apollo\Core\Realtime\Auth\ChannelAuthenticator;
use Apollo\Core\Realtime\Bus\LocalEventBus;
use Apollo\Core\Realtime\Bus\RedisEventBus;
use Apollo\Core\Realtime\Connections\ConnectionManager;
use Apollo\Core\Realtime\Support\RealtimeConfig;
use Apollo\Core\Realtime\Support\RealtimeManager;

/**
 * Servidor WebSocket sobre OpenSwoole.
 *
 * Detecta la extensión y da un error claro si no está disponible.
 * Conecta el EventBus al ChannelManager (local: mismo proceso;
 * redis: bucle de consumo para múltiples instancias).
 */
class WebSocketServer
{
    private RealtimeManager $realtime;
    private RealtimeConfig $config;
    private ?ConnectionManager $connections = null;
    private ?MessageHandler $handler = null;

    public function __construct(RealtimeManager $realtime)
    {
        $this->realtime = $realtime;
        $this->config = $realtime->config();
    }

    public function checkExtension(): void
    {
        if (!extension_loaded('openswoole')) {
            throw new \RuntimeException('Realtime server requires the OpenSwoole PHP extension.');
        }
    }

    public function start(): void
    {
        $this->checkExtension();

        $server = new \OpenSwoole\WebSocket\Server($this->config->host(), $this->config->port());
        $server->set([
            'worker_num' => 1,
            'max_conn' => $this->config->maxConnections(),
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
        ]);

        $this->connections = new ConnectionManager(new \Apollo\Core\Realtime\Channels\ChannelManager(), $this->config->maxConnections());
        $this->handler = new MessageHandler(
            $this->connections,
            new ChannelAuthenticator($this->config),
            $this->config
        );

        // Conectar el bus al ChannelManager para reenviar eventos a clientes
        $this->bridgeBusToChannels();

        $server->on('open', function ($serverCallback, $request) {
            $connection = $this->connections->connect();
            $connection->attachResource(function (array $payload) use ($serverCallback, $request) {
                $serverCallback->push($request->fd, json_encode($payload, JSON_UNESCAPED_UNICODE));
            });

            $this->connections->send($connection->id(), [
                'type' => 'connected',
                'connection_id' => $connection->id(),
            ]);
        });

        $server->on('message', function ($serverCallback, $frame) {
            $connection = $this->connections->getConnection('conn_' . $frame->fd) ?? $this->connections->getConnection($frame->fd) ?? null;

            if (!$connection) {
                $serverCallback->push($frame->fd, json_encode(['type' => 'error', 'code' => 'NOT_CONNECTED']));
                return;
            }

            $connection->touch();
            $decoded = json_decode($frame->data, true);

            if (!is_array($decoded)) {
                $serverCallback->push($frame->fd, json_encode(['type' => 'error', 'code' => 'INVALID_JSON']));
                return;
            }

            foreach ($this->handler->handle($connection, $decoded) as $result) {
                if (isset($result['broadcast'])) {
                    $this->connections->channels()->broadcast(
                        $result['broadcast']['channel'],
                        $result['broadcast']['payload']
                    );
                    continue;
                }

                $serverCallback->push($frame->fd, json_encode($result['payload'], JSON_UNESCAPED_UNICODE));
            }
        });

        $server->on('close', function ($serverCallback, $fd) {
            $this->connections->disconnect('conn_' . $fd);
        });

        // Heartbeat: ping periódico + barrido de conexiones muertas
        $interval = $this->config->heartbeatInterval();
        $timeout = $this->config->connectionTimeout();

        $server->on('workerStart', function () use ($server, $interval, $timeout) {
            \OpenSwoole\Timer::tick($interval * 1000, function () use ($server, $timeout) {
                foreach ($server->connections ?? [] as $fd) {
                    if ($server->isEstablished($fd)) {
                        $server->push($fd, json_encode(['type' => 'ping']));
                    }
                }

                foreach ($this->connections->getConnections() as $connection) {
                    if ($connection->isExpired($timeout) && $connection->resource()) {
                        // no se puede cerrar fd desde aquí sin el server; el timeout lo maneja
                        // la próxima pasada del cliente (o el servidor lo cierra)
                    }
                }
            });
        });

        echo "Realtime server listening on ws://{$this->config->host()}:{$this->config->port()} (driver: {$this->realtime->driver()})\n";

        $server->start();
    }

    /**
     * El bus reenvía los tópicos a los canales del mismo proceso.
     */
    private function bridgeBusToChannels(): void
    {
        $connections = $this->connections;
        $channels = $connections->channels();

        $bus = $this->realtime->bus();

        if ($bus instanceof LocalEventBus) {
            $bus->onPublish(function (string $topic, array $payload) use ($channels) {
                $channels->broadcast($topic, $payload);
            });
            return;
        }

        if ($bus instanceof RedisEventBus) {
            // En multi-instancia, un worker dedicado consume todos los canales
            // (PSUBSCRIBE '*') y reenvía a los clientes de este proceso.
            \OpenSwoole\Process::wait(true); // libera zombies (best-effort)
            $process = new \OpenSwoole\Process(function ($worker) use ($bus, $channels) {
                $bus->consumePattern('*', function (array $payload, string $channel) use ($channels) {
                    $channels->broadcast($channel, $payload);
                });
                $worker->exit(0);
            }, false, 2 /* SOCK_DGRAM */);
            $process->start();
        }
    }
}