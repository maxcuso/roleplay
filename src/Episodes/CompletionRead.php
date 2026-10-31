<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Presentation\CharacterAvatarUrl;

/** Узкие списки для окна. Не открывает административные права справочников. */
final class CompletionRead
{
    public function __construct(private ConnectionInterface $db, private CharacterAvatarUrl $avatars) {}

    public function context(int $discussionId, User $actor): array
    {
        $context = CompletionAccess::context($discussionId, $actor);
        $event = $context['event'];
        $characters = [];
        $ids = $event ? $this->db->table('roleplay_quest_participants')->where('event_id', $event->id)->whereNotNull('character_id')->pluck('character_id') : [];
        foreach ($ids as $id) {
            try { $characters[] = $this->character((int) $id, $actor); }
            catch (ApiProblem $problem) { if ($problem->status !== 404) throw $problem; }
        }
        return ['eventId' => $event ? (int) $event->id : null, 'isQuest' => $event !== null, 'discussionId' => $discussionId, 'title' => $event?->title ?? $context['discussion']->title,
            'revision' => $context['revision'], 'characters' => $characters];
    }

    public function character(int $id, User $actor, bool $quest = true): array
    {
        $row = (new CharacterVisibility())->apply($this->db->table('roleplay_characters'), $actor)->where('id', $id)->first();
        if (!$row) throw new ApiProblem('character_not_found', 'Персонаж не найден или недоступен', 404);
        return ['id' => $id, 'name' => trim($row->first_name.' '.$row->last_name), 'avatarUrl' => $this->avatars->url($row->avatar),
            'available' => $row->status === 'active', 'bonuses' => $quest ? (new RewardExperience($this->db))->bonuses($id) : [],
            'perkIds' => $this->db->table('roleplay_character_perks')->where('character_id', $id)->pluck('perk_id')->map(fn ($value) => (int) $value)->all(),
            'spellIds' => $this->db->table('roleplay_character_spells')->where('character_id', $id)->pluck('spell_id')->map(fn ($value) => (int) $value)->all()];
    }

    public function search(string $kind, string $search, int $page, User $actor): array
    {
        if (!in_array($kind, ['characters', 'items', 'perks', 'spells'], true)) throw new ApiProblem('invalid_catalog', 'Выберите доступный вид награды');
        return (new CatalogRevision($this->db))->read(function (int $revision) use ($kind, $search, $page, $actor): array {
            $page = min(100000, max(1, $page));
            $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query = $kind === 'characters'
                ? (new CharacterVisibility())->apply($this->db->table('roleplay_characters')->where('status', 'active'), $actor)
                : $this->db->table('roleplay_'.CatalogRegistry::tableFor($kind))->where('enabled', true);
            if ($search !== '') {
                if ($kind === 'characters') \Maxcuso\Roleplay\Character\Profile\CharacterNameSearch::apply($query, $search);
                else $query->where('name', 'like', $literal);
            }
            $total = $query->count();
            if ($kind !== 'characters') $query->orderBy('sort_order');
            $ids = $query->orderBy('id')->offset(($page - 1) * 30)->limit(30)->pluck('id')->map(fn ($value) => (int) $value)->all();
            if ($kind === 'characters') {
                $rows = $ids ? $this->db->table('roleplay_characters')->whereIn('id', $ids)->orderBy('id')->get()
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => trim($row->first_name.' '.$row->last_name), 'avatarUrl' => $this->avatars->url($row->avatar)])->all() : [];
            } else {
                $rows = array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'name', 'level', 'image', 'icon', 'enabled'])),
                    (new CatalogRegistry($this->db))->definitionsFor($kind, $ids));
            }
            return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => 30, 'revision' => $revision, 'lookups' => []];
        });
    }
}
