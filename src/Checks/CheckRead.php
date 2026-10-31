<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Character\Calculation\CharacterCalculationBuilder;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Character\State\{CharacterStateReader, CharacterSnapshotBuilder};
use Maxcuso\Roleplay\Creation\CreationCatalog;
use Maxcuso\Roleplay\Events\QuestEvent;
use Maxcuso\Roleplay\Presentation\CharacterAvatarUrl;
use Maxcuso\Roleplay\Rules\DevelopmentConfigurationStore;

final class CheckRead
{
    public function __construct(private ConnectionInterface $db, private CharacterAvatarUrl $avatars) {}

    public function context(?int $discussionId, User $actor, ?string $requestId = null): array
    {
        CheckAccess::discussion($discussionId, $actor);
        $request = $requestId ? CheckAccess::request($this->db, $requestId, $actor, $discussionId) : null;
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($discussionId, $actor, $requestId, $request): array {
            $definitions = $catalog['definitions'];
            $attributes = array_values(array_map(fn ($r) => array_intersect_key($r, array_flip(['id', 'name', 'icon', 'slug'])),
                array_filter($definitions['attributes'], fn ($r) => $r['enabled'])));
            $skills = array_values(array_map(fn ($r) => array_intersect_key($r, array_flip(['id', 'name', 'icon', 'attribute_id'])),
                array_filter($definitions['skills'], fn ($r) => $r['enabled'])));
            $rows = $this->db->table('roleplay_characters')->where('user_id', $actor->id)->where('status', 'active')->orderBy('id')->get(['id', 'first_name', 'last_name', 'avatar']);
            $characters = [];
            foreach ($rows as $row) {
                if (CheckAccess::eligible($this->db, $discussionId, (int) $row->id, $request)) {
                    $characters[] = ['id' => (int) $row->id, 'name' => trim($row->first_name.' '.$row->last_name), 'avatarUrl' => $this->avatars->url($row->avatar)];
                }
            }
            $event = $discussionId ? QuestEvent::where('discussion_id', $discussionId)->where('event_type', 'quest')->first() : null;
            $bound = $event ? $this->db->table('roleplay_quest_participants')->where('event_id', $event->id)->where('user_id', $actor->id)->value('character_id') : null;
            $ids = array_column($characters, 'id');
            return ['discussionId' => $discussionId, 'requestId' => $requestId, 'request' => $request === null ? null
                : array_intersect_key($request, array_flip(['kind', 'target_id', 'difficulty'])),
                'attributes' => $attributes, 'skills' => $skills, 'characters' => $characters,
                'defaultCharacterId' => $bound !== null && in_array((int) $bound, $ids, true) ? (int) $bound : (count($ids) === 1 ? $ids[0] : null),
                'difficulties' => array_map(fn ($value, $name) => ['value' => $value, 'name' => $name], array_keys(CheckInput::DIFFICULTIES), array_values(CheckInput::DIFFICULTIES))];
        });
    }

    public function character(int $id, User $actor, ?int $discussionId, ?string $requestId): array
    {
        CheckAccess::discussion($discussionId, $actor);
        $request = $requestId ? CheckAccess::request($this->db, $requestId, $actor, $discussionId) : null;
        if (!CheckAccess::eligible($this->db, $discussionId, $id, $request)) throw new ApiProblem('check_recipient', 'Выберите персонажа, которому адресована проверка', 403);
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $actor): array {
            $reader = new CharacterStateReader($this->db);
            $state = $reader->read($id, function (array $row) use ($actor): void {
                if ((int) $row['user_id'] !== (int) $actor->id || $row['status'] !== 'active') throw new ApiProblem('check_character', 'Выберите своего активного персонажа', 403);
            });
            $rules = (new DevelopmentConfigurationStore($this->db))->read();
            $snapshot = (new CharacterSnapshotBuilder($reader, $rules->learning()))->fromState($state);
            $calculation = CharacterCalculationBuilder::defaults($rules)->calculate($snapshot);
            $attributes = [];
            foreach ($calculation['effective_attributes'] as $attribute) {
                $extra = $calculation['check_effects']['attributes'][$attribute['id']]->value ?? 0;
                $attributes[$attribute['id']] = ['id' => $attribute['id'], 'name' => $attribute['name'], 'value' => $attribute['level'],
                    'bonus' => $attribute['level'] - 5 + $extra, 'extra' => $extra];
            }
            $levels = array_column($snapshot->skills, 'level', 'id'); $skills = [];
            foreach ($catalog['definitions']['skills'] as $skill) {
                if (!$skill['enabled'] || !isset($attributes[$skill['attribute_id']])) continue;
                $level = $levels[$skill['id']] ?? 0;
                $rankBonus = $calculation['skill_check_bonuses'][$skill['id']] ?? ($calculation['check_effects']['skills'][$skill['id']]->value ?? $calculation['check_effects']['all']->value);
                $skills[$skill['id']] = ['id' => $skill['id'], 'name' => $skill['name'], 'attribute_id' => $skill['attribute_id'],
                    'level' => $level, 'bonus' => $attributes[$skill['attribute_id']]['value'] - 5 + $rankBonus, 'extra' => $rankBonus - $level];
            }
            return ['id' => $id, 'revision' => $snapshot->character['revision'], 'name' => trim($snapshot->character['first_name'].' '.$snapshot->character['last_name']),
                'attributes' => array_values($attributes), 'skills' => array_values($skills)];
        });
    }

    public function definition(array $payload): array
    {
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($payload): array {
            $row = $catalog['definitions'][$payload['kind'] === 'skill' ? 'skills' : 'attributes'][$payload['target_id']] ?? null;
            if (!$row || !$row['enabled']) throw new ApiProblem('check_definition', 'Выберите доступный навык или характеристику');
            return $row;
        });
    }

    public function targets(User $actor, string $search, int $page): array
    {
        $query = (new CharacterVisibility())->apply($this->db->table('roleplay_characters')->where('status', 'active'), $actor);
        \Maxcuso\Roleplay\Character\Profile\CharacterNameSearch::apply($query, $search);
        $total = $query->count();
        return ['rows' => $query->orderBy('id')->offset(($page - 1) * 30)->limit(30)->get(['id', 'first_name', 'last_name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => trim($row->first_name.' '.$row->last_name)])->all(),
            'page' => $page, 'total' => $total];
    }

    public function validateRecipients(array $payload, User $actor): void
    {
        if (($payload['target'] ?? null) !== 'selected') return;
        $count = (new CharacterVisibility())->apply($this->db->table('roleplay_characters')->where('status', 'active'), $actor)->whereIn('id', $payload['characters'])->count();
        if ($count !== count($payload['characters'])) throw new ApiProblem('check_recipient', 'Обновите список и выберите доступных персонажей');
    }
}
