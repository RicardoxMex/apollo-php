<?php

namespace Apollo\Core\Realtime\Notifications;

use Apollo\Core\Realtime\Contracts\RealtimeEvent;

/**
 * Notificación base: la app crea una por dominio, define por qué canales
 * se envía (database, realtime...) y qué datos expone.
 *
 *   class ProductCreated extends Notification {
 *       public function title(): string { return 'Producto creado'; }
 *       public function message(): string { return ...; }
 *       public function channels(): array { return ['database', 'realtime']; }
 *       public function toRealtime(): ?RealtimeEvent { ... }
 *   }
 */
abstract class Notification
{
    public function id(): string
    {
        return 'notif_' . bin2hex(random_bytes(8));
    }

    public function type(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    abstract public function title(): string;

    abstract public function message(): string;

    /**
     * Canales a los que se despacha (database, realtime...).
     */
    public function channels(): array
    {
        return ['database'];
    }

    /**
     * Datos extra persistidos (JSON).
     */
    public function data(): array
    {
        return [];
    }

    /**
     * Evento realtime opcional (emitido solo si el canal 'realtime' está activo).
     */
    public function toRealtime(): ?RealtimeEvent
    {
        return null;
    }

    /**
     * Serialización para persistencia.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'message' => $this->message(),
            'data' => $this->data(),
        ];
    }

    /**
     * Enviar la notificación por los canales configurados:
     *   Notification::send($userId, new OrderShipped());
     */
    public static function send(int|string $userId, self $notification): array
    {
        return app(\Apollo\Core\Realtime\Notifications\NotificationManager::class)->send($userId, $notification);
    }
}