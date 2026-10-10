<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Operations\ActorOperationLock;

/** Совместимый вход сервера создания; сам мьютекс общий с операциями экипировки. */
final class CreationActorLock
{
    public static function acquire(ConnectionInterface $db, int $userId): void
    {
        ActorOperationLock::acquire($db, $userId);
    }
}