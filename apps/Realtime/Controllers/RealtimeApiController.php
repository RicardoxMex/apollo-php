<?php

namespace Apps\Realtime\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Realtime\Auth\ChannelAuthenticator;
use Apollo\Core\Realtime\Notifications\NotificationManager;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;
use Apollo\Core\Realtime\Support\RealtimeManager;

class RealtimeApiController
{
    private RealtimeManager $realtime;

    public function __construct(Container $container, RealtimeManager $realtime)
    {
        $this->realtime = $realtime;
    }

    /**
     * POST /v1/events — publicar un evento con app_secret.
     */
    public function publish(Request $request): Response
    {
        if (!$this->authorizeAppSecret($request)) {
            return $this->error('UNAUTHORIZED', 'API key inválida', 401);
        }

        $body = $request->json() ?? [];

        $channel = $body['channel'] ?? null;
        $event = $body['event'] ?? null;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (!$channel || !$event) {
            return $this->error('VALIDATION_ERROR', 'channel y event son requeridos', 400);
        }

        $this->realtime->broadcast((string) $channel, (string) $event, $data);

        return Response::json(['success' => true, 'channel' => $channel, 'event' => $event]);
    }

    /**
     * POST /v1/realtime/auth — ticket firmado para canales privados/presence.
     */
    public function authorize(Request $request): Response
    {
        if (!$this->authorizeAppSecret($request)) {
            // Si el request viene de un cliente autenticado JWT, también vale
            $user = $request->user();

            if (!$user) {
                return $this->error('UNAUTHORIZED', 'API key o usuario requerido', 401);
            }
        }

        $body = $request->json() ?? [];
        $channel = $body['channel'] ?? null;

        if (!$channel) {
            return $this->error('VALIDATION_ERROR', 'channel requerido', 400);
        }

        $userId = $body['user_id'] ?? ($request->user()->id ?? null);

        if ($userId === null) {
            return $this->error('VALIDATION_ERROR', 'user_id requerido', 400);
        }

        $authenticator = new ChannelAuthenticator($this->realtime->config());

        if (!$authenticator->authorize((string) $channel, (int) $userId, $body)) {
            return $this->error('CHANNEL_UNAUTHORIZED', 'Canal no autorizado', 403);
        }

        return Response::json([
            'channel' => $channel,
            'auth' => $authenticator->sign((string) $channel, (int) $userId),
            'user_id' => $userId,
        ]);
    }

    public function channels(Request $request): Response
    {
        if (!$this->authorizeAppSecret($request)) {
            return $this->error('UNAUTHORIZED', 'API key inválida', 401);
        }

        return Response::json([
            'driver' => $this->realtime->driver(),
            'host' => $this->realtime->config()->host(),
            'port' => $this->realtime->config()->port(),
            'channels' => [], // la vista en vivo la expone el servidor WebSocket
        ]);
    }

    /**
     * GET /v1/notifications — notificaciones del usuario autenticado.
     */
    public function notifications(Request $request): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('UNAUTHORIZED', 'Autenticación requerida', 401);
        }

        $repo = new MySqlNotificationRepository();

        return Response::json([
            'success' => true,
            'data' => $repo->forUser((int) $user->id, ['unread' => (bool) $request->query('unread', false)]),
        ]);
    }

    public function markAsRead(Request $request, string $id): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('UNAUTHORIZED', 'Autenticación requerida', 401);
        }

        $repo = new MySqlNotificationRepository();
        $repo->markAsRead($id);

        return Response::json(['success' => true, 'id' => $id]);
    }

    private function authorizeAppSecret(Request $request): bool
    {
        $secret = $this->realtime->config()->appSecret();

        if ($secret === '') {
            return false;
        }

        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $token = substr($header, 7);

        return hash_equals($secret, $token);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return Response::json([
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}