<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Tests\Support;

use Illuminate\Queue\SyncQueue;
use Maxcuso\Roleplay\Notification\CharacterNotifications;
use Psr\Log\NullLogger;

/** Изолированные таблицы не отправляют уведомления настоящим пользователям форума. */
final class NotificationServices
{
    public static function make(): CharacterNotifications
    {
        $queue = new class extends SyncQueue {
            public function push($job, $data = '', $queue = null) { return 'isolated-test-job'; }
        };
        return new CharacterNotifications($queue, new NullLogger());
    }
}
