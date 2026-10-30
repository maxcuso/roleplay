<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

final class CharacterNotifications
{
    /** Регистрируется внутри транзакции. Откат отменяет callback, внешняя фиксация запускает очередь. */
    public static function afterEvent(ConnectionInterface $db, array $character, string $event, array $details, int $eventId, int $actorId): void
    {
        $data = CharacterNotificationData::make($character, $event, $details, $eventId, $actorId);
        if ($data === null) return;
        if ($data['kind'] === 'literature_approved' && !empty($character['discussion_id'])) {
            $data['discussionId'] = (int) $character['discussion_id'];
            $data['postNumber'] = isset($details['acceptance_post_id'])
                ? $db->table('posts')->where('id', $details['acceptance_post_id'])->where('discussion_id', $character['discussion_id'])->value('number') : null;
        }
        $db->afterCommit(function () use ($character, $actorId, $data): void {
            try {
                resolve(Queue::class)->push(new SendCharacterNotification((int) $character['user_id'], $actorId, $data));
            } catch (\Throwable $error) {
                // Уже выполненная выдача не должна выглядеть неудачной из-за сбоя уведомления.
                resolve(LoggerInterface::class)->error('Roleplay notification delivery failed', ['event_id' => $data['eventId'], 'exception' => $error]);
            }
        });
    }
}
