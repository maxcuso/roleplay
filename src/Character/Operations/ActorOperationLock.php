<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Operations;

use Illuminate\Database\ConnectionInterface;

/** Сериализует команды одного аккаунта, включая первый запрос без существующего персонажа. */
final class ActorOperationLock
{
    public static function acquire(ConnectionInterface $db, int $userId): void
    {
        $db->table('roleplay_actor_states')->insertOrIgnore(['user_id' => $userId, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->table('roleplay_actor_states')->where('user_id', $userId)->lockForUpdate()->first();
    }
}