<?php

namespace Apollo\Core\Realtime\Channels;

/**
 * Canal de presencia: miembros en memoria + eventos member.joined/member.left.
 * (Presencia distribuida con Redis se añade en fases posteriores.)
 */
class PresenceChannel extends PrivateChannel
{
    protected string $type = 'presence';

    /** @var array connectionId => userInfo */
    private array $members = [];

    /** @var array userId => info (último presente) */
    private array $users = [];

    public function addMember(string $connectionId, int|string $userId, array $userInfo = []): array
    {
        $this->members[$connectionId] = [
            'id' => $userId,
            'info' => $userInfo,
        ];

        $this->users[$userId] = $userInfo;
        $this->authorizedUsers[$connectionId] = $userId;

        return [
            'member' => ['id' => $userId, 'info' => $userInfo],
            'members' => $this->getMembers(),
        ];
    }

    public function removeMember(string $connectionId): ?array
    {
        if (!isset($this->members[$connectionId])) {
            return null;
        }

        $member = $this->members[$connectionId];
        unset($this->members[$connectionId]);

        return $member;
    }

    public function getMembers(): array
    {
        return array_values($this->members);
    }

    public function memberCount(): int
    {
        return count($this->members);
    }
}