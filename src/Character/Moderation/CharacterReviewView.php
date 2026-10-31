<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Moderation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;

/** Чтение статуса анкеты не требует очереди уведомлений и сервиса изменения персонажа. */
final class CharacterReviewView
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics) {}

    public function present(array $character, User $actor): array
    {
        $topic = $character['discussion_id'] ? $this->topics->inspect($actor, (int) $character['discussion_id'])
            : ['available' => false, 'hash' => null];
        $literature = self::literatureStatus($character, $topic);
        $private = ReviewPermissions::owner($character, $actor) || ReviewPermissions::staff($actor);
        $history = [];
        if ($private) {
            $history = $this->db->table('roleplay_character_events')->where('character_id', $character['id'])
                ->whereIn('type', ['review_decided', 'review_submitted', 'identity_corrected', 'mechanics_updated'])
                ->orderByDesc('id')->limit(20)->get()->map(fn ($row) => [
                    'id' => (int) $row->id, 'type' => $row->type, 'actor_id' => (int) $row->actor_user_id,
                    'created_at' => $row->created_at, 'details' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
                ])->all();
        }
        $permissions = ReviewPermissions::describe($character, $actor, $literature);
        $permissions['can_submit_literature'] = $permissions['can_submit_literature'] && $topic['available'];
        $permissions['can_withdraw_literature'] = $permissions['can_withdraw_literature'] && empty($topic['accepted']);
        return [
            'mechanics_status' => $character['mechanics_status'], 'literature_status' => $literature,
            'topic_available' => $topic['available'], 'topic_hash' => $private ? $topic['hash'] : null,
            'permissions' => $permissions, 'history' => $history,
        ];
    }

    public static function literatureStatus(array $character, array $topic): string
    {
        $status = $character['literature_status'];
        // У активных персонажей сохраняется историческое одобрение.
        // null у одобрённой создаваемой анкеты — сознательная перепривязка администратором.
        if ($character['status'] === 'creating' && $status === 'approved' && $character['literature_hash'] !== null
            && (empty($topic['accepted']) || !$topic['available'] || !hash_equals($character['literature_hash'], $topic['hash']))) {
            return 'review';
        }
        return $status;
    }
}
