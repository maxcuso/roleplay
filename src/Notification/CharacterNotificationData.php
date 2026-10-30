<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

/** Только выбор типа и снимок текста. Здесь нет доставки, SQL или изменения персонажа. */
final class CharacterNotificationData
{
    public static function make(array $character, string $event, array $details, int $eventId, int $actorId): ?array
    {
        if ((int) $character['user_id'] === $actorId) return null;
        $kind = null;
        if ($event === 'review_decided') {
            if (!empty($details['activated'])) $kind = 'activated';
            elseif (($details['before'] ?? null) !== ($details['after'] ?? null)) {
                $part = $details['part'] ?? '';
                if ($details['after'] === 'approved') $kind = $part === 'mechanics' ? 'mechanics_approved' : 'literature_approved';
                elseif ($part === 'mechanics' && $details['after'] === 'changes_requested') $kind = 'mechanics_changes';
                elseif ($part === 'literature' && $details['before'] === 'approved' && $details['after'] === 'review') $kind = 'literature_review';
            }
        } elseif ($event === 'character_managed') {
            $action = $details['action'] ?? '';
            if (in_array($action, ['episode_reward', 'topic_reward'], true)) {
                if (($details['experience'] ?? 0) || ($details['gold'] ?? 0) || !empty($details['grants'])) $kind = $action;
            } elseif ($action === 'set_rank') $kind = empty($details['after']) ? 'rank_removed' : 'rank_changed';
            elseif (in_array($action, ['reward', 'balance'], true)) {
                $experience = (int) ($details['experience'] ?? (($details['resource'] ?? '') === 'experience_active' ? $details['delta'] : 0));
                $gold = (int) ($details['gold'] ?? (($details['resource'] ?? '') === 'gold' ? $details['delta'] : 0));
                if ($experience || $gold) $kind = $experience < 0 || $gold < 0 ? 'deduction' : 'reward';
            } elseif (in_array($action, ['grant_item', 'remove_item', 'grant_perk', 'remove_perk', 'grant_spell', 'remove_spell'], true)) $kind = $action;
        }
        if ($kind === null) return null;
        $data = ['eventId' => $eventId, 'characterId' => (int) $character['id'],
            'characterName' => trim($character['first_name'].' '.($character['last_name'] ?? '')), 'kind' => $kind];
        if (isset($experience, $gold)) $data += ['experience' => abs($experience), 'gold' => abs($gold)];
        if (str_starts_with($kind, 'grant_') || str_starts_with($kind, 'remove_')) {
            $data += ['name' => (string) ($details['name'] ?? ''), 'quantity' => (int) ($details['quantity'] ?? 1)];
        }
        if ($kind === 'rank_changed') $data['name'] = (string) $details['name'];
        if ($kind === 'episode_reward') $data['name'] = (string) $details['episode_title'];
        return $data;
    }
}
