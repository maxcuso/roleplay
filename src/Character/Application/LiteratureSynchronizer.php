<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Application;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;

/** События форума синхронизируют решение. GET профиля не меняет персонажа. */
final class LiteratureSynchronizer
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics, private readonly \Maxcuso\Roleplay\Notification\CharacterNotifications $notifications) {}

    public function synchronize(int $discussionId, User $actor): void
    {
        (new CatalogRevision($this->db))->read(function () use ($discussionId, $actor): void {
            $character = $this->db->table('roleplay_characters')->where('discussion_id', $discussionId)->lockForUpdate()->first();
            if (!$character || $character->status !== 'creating') return;
            $topic = $this->topics->inspect($actor, $discussionId);
            // Администраторская перепривязка ранее принятой анкеты сохраняет решение.
            $preserved = $character->literature_status === 'approved' && $character->literature_hash === null;
            $accepted = !empty($topic['accepted']) || $preserved;
            // Редактирование шаблона не отправляет его на проверку за игрока.
            // Снятие уже наблюдённого одобрения возвращает прежнюю проверку.
            $status = $accepted ? 'approved' : ($character->literature_status === 'approved' ? 'review' : $character->literature_status);
            $hash = $accepted && !$preserved ? $topic['hash'] : null;
            $activate = $accepted && $character->mechanics_status === 'approved' && $topic['available'];
            if ($character->literature_status === $status && $character->literature_hash === $hash && !$activate) return;
            $now = gmdate('Y-m-d H:i:s');
            if ($activate) {
                (new \Maxcuso\Roleplay\Character\Profile\RoleplayerStatistics($this->db))
                    ->activated((int) $character->user_id, $now);
            }
            $key = (string) \Ramsey\Uuid\Uuid::uuid4();
            $reviewerId = (int) ($topic['acceptance_user_id'] ?? $actor->id);
            $result = ['character_id' => (int) $character->id, 'revision' => (int) $character->revision + 1];
            $this->db->table('roleplay_operations')->insert([
                'id' => $key, 'type' => 'literature_observed', 'actor_user_id' => $reviewerId,
                'character_id' => $character->id, 'request_hash' => hash('sha256', json_encode($topic, JSON_THROW_ON_ERROR)),
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => $now,
            ]);
            $this->db->table('roleplay_characters')->where('id', $character->id)->update([
                'literature_status' => $status, 'literature_approved' => $accepted, 'literature_hash' => $hash,
                'status' => $activate ? 'active' : 'creating', 'revision' => $result['revision'],
                'lore_revision' => (int) $character->lore_revision + 1, 'updated_at' => $now,
            ]);
            $details = ['part' => 'literature', 'action' => 'observe', 'before' => $character->literature_status,
                'after' => $status, 'activated' => $activate, 'comment' => '',
                'acceptance_post_id' => $topic['acceptance_post_id'] ?? null];
            $eventId = $this->db->table('roleplay_character_events')->insertGetId([
                'character_id' => $character->id, 'actor_user_id' => $reviewerId, 'operation_id' => $key,
                'type' => 'review_decided', 'created_at' => $now,
                'payload' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);
            $this->notifications->afterEvent($this->db, (array) $character,
                'review_decided', $details, $eventId, $reviewerId);
        });
    }
}
