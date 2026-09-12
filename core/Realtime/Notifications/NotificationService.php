<?php

namespace Apollo\Core\Realtime\Notifications;

use Apollo\Core\Realtime\Contracts\NotificationRepository;

/**
 * NotificationService — API de alto nivel para enviar notificaciones a usuarios.
 *
 *   $notificationService->sendToUser(
 *       $userId,
 *       'ticket.created',
 *       [
 *           'title' => 'Nuevo ticket',
 *           'message' => 'Se creó el ticket #123',
 *           'data' => ['ticket_id' => 123],
 *       ]
 *   );
 *
 * Responsabilidad única: persistir la notificación y devolver el registro creado
 * (id, type, title, message, data, created_at). La entrega en vivo al cliente
 * WebSocket la hace el servidor Workerman por polling de la tabla `notifications`
 * (D4-revisado). Este diseño:
 *  - Desacopla la lógica de negocio del transporte WebSocket.
 *  - Funciona cross-process: el proceso HTTP persiste; el proceso WS entrega.
 *  - Si el servidor WS está caído, la notificación queda persistida y se entrega
 *    al reconectar (el polling usa high-water-mark por usuario).
 *  - No requiere canales privados ni tickets HMAC para esta vía.
 */
class NotificationService
{
    private NotificationRepository $repository;

    public function __construct(NotificationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Persiste una notificación para el usuario y devuelve el registro.
     *
     * @param  int|string $userId
     * @param  string     $type     Tipo semántico (p. ej. 'ticket.created')
     * @param  array      $payload  ['title' => …, 'message' => …, 'data' => […]]
     * @return array{id:string,type:string,title:string,message:string,data:array,created_at:?string}
     */
    public function sendToUser(int|string $userId, string $type, array $payload = []): array
    {
        $title = (string) ($payload['title'] ?? '');
        $message = (string) ($payload['message'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        $id = $this->repository->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'read_at' => null,
        ]);

        $record = $this->repository->find($id);

        return [
            'id' => $record['id'] ?? $id,
            'type' => $record['type'] ?? $type,
            'title' => $record['title'] ?? $title,
            'message' => $record['message'] ?? $message,
            'data' => $record['data'] ?? $data,
            'created_at' => $record['created_at'] ?? null,
        ];
    }

    /**
     * Atajo para enviar a varios usuarios el mismo payload.
     *
     * @param  int[]|int|string[] $userIds
     * @return array<int, array>  Lista de registros creados (uno por usuario)
     */
    public function sendToUsers(array $userIds, string $type, array $payload = []): array
    {
        $out = [];
        foreach ($userIds as $userId) {
            $out[] = $this->sendToUser($userId, $type, $payload);
        }
        return $out;
    }
}
