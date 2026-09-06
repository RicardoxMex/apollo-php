<?php

namespace Apollo\Core\Realtime\WebSocket;

use Apollo\Core\Realtime\Auth\ChannelAuthenticator;
use Apollo\Core\Realtime\Channels\ChannelManager;
use Apollo\Core\Realtime\Channels\PresenceChannel;
use Apollo\Core\Realtime\Connections\Connection;
use Apollo\Core\Realtime\Connections\ConnectionManager;
use Apollo\Core\Realtime\Support\RealtimeConfig;

/**
 * Interpreta los frames JSON del cliente y genera las respuestas del
 * protocolo (subscribe/unsubscribe/ping/event). Lógica pura: el servidor
 * solo conecta la Connection con su fd.
 */
class MessageHandler
{
    private ConnectionManager $connections;
    private ChannelAuthenticator $authenticator;
    private RealtimeConfig $config;

    /** @var callable|null */
    private $onMemberJoined;

    /** @var callable|null */
    private $onMemberLeft;

    public function __construct(
        ConnectionManager $connections,
        ChannelAuthenticator $authenticator,
        RealtimeConfig $config
    ) {
        $this->connections = $connections;
        $this->authenticator = $authenticator;
        $this->config = $config;
    }

    /**
     * Procesar un frame. Devuelve las respuestas a enviar (array de payloads),
     * o el payload a enviar al resto del canal (presence member events).
     *
     * @return array<int, array{payload: array, broadcast?: array{channel: string, payload: array}}>
     */
    public function handle(Connection $connection, array $frame): array
    {
        $connection->touch();
        $type = $frame['type'] ?? null;

        return match ($type) {
            'ping', 'heartbeat' => [['payload' => ['type' => 'pong', 'time' => time()]]],
            'subscribe' => $this->handleSubscribe($connection, $frame),
            'unsubscribe' => $this->handleUnsubscribe($connection, $frame),
            'authenticate' => $this->handleAuthenticate($connection, $frame),
            default => [['payload' => [
                'type' => 'error',
                'code' => 'INVALID_MESSAGE',
                'message' => 'Tipo de mensaje no soportado',
            ]]],
        };
    }

    private function handleSubscribe(Connection $connection, array $frame): array
    {
        $channelName = $frame['channel'] ?? null;

        if (!$channelName || !is_string($channelName)) {
            return [['payload' => ['type' => 'error', 'code' => 'INVALID_CHANNEL', 'message' => 'channel requerido']]];
        }

        if (strlen((string) json_encode($frame)) > $this->config->maxMessageSize()) {
            return [['payload' => ['type' => 'error', 'code' => 'MESSAGE_TOO_LARGE', 'message' => 'Mensaje excede el tamaño permitido']]];
        }

        $type = ChannelManager::channelType($channelName);

        if ($type === ChannelManager::PUBLIC) {
            $connection->subscribe($channelName);

            return [['payload' => [
                'type' => 'subscribed',
                'channel' => $channelName,
            ]]];
        }

        // Privado / presence: exige ticket firmado o decisión explícita de la app.
        $userId = $frame['user_id'] ?? null;
        $ticket = $frame['auth'] ?? null;

        $authed = false;

        if ($userId !== null && is_string($ticket) && $ticket !== '') {
            $authed = $this->authenticator->verifyTicket($channelName, (int) $userId, $ticket);
        }

        if (!$authed && $userId !== null) {
            $authed = $this->authenticator->authorize($channelName, (int) $userId, $frame);
        }

        if (!$authed) {
            return [['payload' => [
                'type' => 'error',
                'code' => 'CHANNEL_UNAUTHORIZED',
                'message' => 'Channel authorization failed',
            ]]];
        }

        $connection->setUserId((int) $userId);

        if ($type === ChannelManager::PRESENCE) {
            $channel = $this->connections->channels()->getOrCreate($channelName);
            $connection->subscribe($channelName);

            if ($channel instanceof PresenceChannel) {
                $result = $channel->addMember($connection->id(), (int) $userId, $frame['user_info'] ?? []);

                $joined = [
                    'type' => 'presence',
                    'channel' => $channelName,
                    'event' => 'member.joined',
                    'data' => $result,
                ];

                return [
                    ['payload' => ['type' => 'subscribed', 'channel' => $channelName, 'members' => $result['members']]],
                    ['broadcast' => ['channel' => $channelName, 'payload' => $joined]],
                ];
            }
        }

        $connection->subscribe($channelName);

        return [['payload' => ['type' => 'subscribed', 'channel' => $channelName]]];
    }

    private function handleUnsubscribe(Connection $connection, array $frame): array
    {
        $channelName = $frame['channel'] ?? null;

        if (!$channelName) {
            return [['payload' => ['type' => 'error', 'code' => 'INVALID_CHANNEL', 'message' => 'channel requerido']]];
        }

        $connection->unsubscribe($channelName);

        return [['payload' => ['type' => 'unsubscribed', 'channel' => $channelName]]];
    }

    private function handleAuthenticate(Connection $connection, array $frame): array
    {
        $userId = $frame['user_id'] ?? null;

        if ($userId !== null) {
            $connection->setUserId((int) $userId);
        }

        return [['payload' => ['type' => 'authenticated', 'user_id' => $connection->userId()]]];
    }
}