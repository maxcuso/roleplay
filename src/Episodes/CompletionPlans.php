<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;

/** Вставка создаёт только намерение. UUID — ключ повтора, а не право выдачи. */
final class CompletionPlans
{
    public function __construct(private ConnectionInterface $db) {}

    public function prepare(int $discussionId, User $actor, mixed $input): array
    {
        $body = OperationRequest::body($input, ['operation_key', 'payload']);
        $id = OperationRequest::key($body['operation_key'] ?? null);
        $payload = CompletionInput::payload($body['payload'] ?? null);
        return (new \Maxcuso\Roleplay\Catalog\Shared\CatalogRevision($this->db))->read(function () use ($discussionId, $actor, $id, $payload): array {
            \Maxcuso\Roleplay\Character\Operations\ActorOperationLock::acquire($this->db, (int) $actor->id);
            $context = CompletionAccess::context($discussionId, $actor);
            $event = $context['event'];
            $eventId = $event ? (int) $event->id : null;
            if ($payload['discussion_id'] !== $discussionId || $payload['event_id'] !== $eventId
                || !hash_equals($context['revision'], $payload['revision'])) {
                throw new ApiProblem('episode_revision_conflict', 'Данные темы изменились. Обновите окно выдачи наград', 409);
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $previous = $this->db->table('roleplay_episode_plans')->where('id', $id)->first();
            if ($previous) {
                if ((int) $previous->actor_user_id !== (int) $actor->id || !hash_equals($previous->payload, $json)) {
                    throw new ApiProblem('completion_key_conflict', 'Повторный запрос отличается от исходного. Обновите окно завершения', 409);
                }
            } else {
                foreach ($payload['recipients'] as $recipient) {
                    $character = (new CompletionRead($this->db))->character($recipient['character_id'], $actor, $event !== null);
                    if (!$character['available']) throw new ApiProblem('recipient_unavailable', 'Выберите активных получателей награды', 409);
                }
                $this->db->table('roleplay_episode_plans')->insert(['id' => $id, 'event_id' => $eventId,
                    'discussion_id' => $discussionId, 'actor_user_id' => $actor->id, 'payload' => $json, 'created_at' => gmdate('Y-m-d H:i:s')]);
            }
            return ['code' => '[episode-end='.$id.']'];
        });
    }
}
