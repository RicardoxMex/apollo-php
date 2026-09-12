<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Contracts\NotificationRepository;
use Apollo\Core\Realtime\Notifications\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
    public function test_send_to_user_persists_and_returns_record(): void
    {
        $created = null;

        $repo = $this->createMock(NotificationRepository::class);
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data) {
                return $data['user_id'] === 42
                    && $data['type'] === 'ticket.created'
                    && $data['title'] === 'Nuevo ticket'
                    && $data['message'] === 'Se creó el ticket #7'
                    && $data['data'] === ['ticket_id' => 7];
            }))
            ->willReturnCallback(function (array $data) use (&$created) {
                $created = $data;
                return 'notif_test123';
            });

        $repo->expects($this->once())
            ->method('find')
            ->with('notif_test123')
            ->willReturn([
                'id' => 'notif_test123',
                'user_id' => 42,
                'type' => 'ticket.created',
                'title' => 'Nuevo ticket',
                'message' => 'Se creó el ticket #7',
                'data' => ['ticket_id' => 7],
                'read_at' => null,
                'created_at' => '2026-09-11 10:00:00',
                'updated_at' => '2026-09-11 10:00:00',
            ]);

        $service = new NotificationService($repo);
        $result = $service->sendToUser(42, 'ticket.created', [
            'title' => 'Nuevo ticket',
            'message' => 'Se creó el ticket #7',
            'data' => ['ticket_id' => 7],
        ]);

        $this->assertSame('notif_test123', $result['id']);
        $this->assertSame('ticket.created', $result['type']);
        $this->assertSame('Nuevo ticket', $result['title']);
        $this->assertSame('Se creó el ticket #7', $result['message']);
        $this->assertSame(['ticket_id' => 7], $result['data']);
        $this->assertSame('2026-09-11 10:00:00', $result['created_at']);
    }

    public function test_send_to_users_returns_one_record_per_user(): void
    {
        $repo = $this->createMock(NotificationRepository::class);
        $repo->method('create')->willReturnOnConsecutiveCalls('notif_1', 'notif_2', 'notif_3');
        $repo->method('find')->willReturnCallback(function ($id) {
            return [
                'id' => $id,
                'user_id' => 0,
                'type' => 'broadcast',
                'title' => 't',
                'message' => 'm',
                'data' => [],
                'read_at' => null,
                'created_at' => null,
            ];
        });

        $service = new NotificationService($repo);
        $records = $service->sendToUsers([1, 2, 3], 'broadcast', ['title' => 't', 'message' => 'm', 'data' => []]);

        $this->assertCount(3, $records);
        $this->assertSame('notif_1', $records[0]['id']);
        $this->assertSame('notif_3', $records[2]['id']);
    }
}
