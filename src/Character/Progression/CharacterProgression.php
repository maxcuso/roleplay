<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Progression;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Operations\CharacterOperation;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Character\State\CharacterGrants;
use Maxcuso\Roleplay\Character\State\CharacterSnapshotBuilder;
use Maxcuso\Roleplay\Character\State\CharacterStateReader;
use Maxcuso\Roleplay\Creation\CreationCatalog;
use Maxcuso\Roleplay\Rules\DevelopmentRules;

/** Операции игрока: списание опыта или одного заработанного права.
 * Цена, доступность и выдачи вычисляются сервером; клиент присылает только тип и ID.
 */
final class CharacterProgression
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function change(int $id, User $actor, mixed $input): array
    {
        $body = OperationRequest::body($input, ['operation_key', 'revision', 'catalog_revision', 'kind', 'target_id', 'rules_hash']);
        $kind = $body['kind'] ?? null;
        if (!in_array($kind, ['attribute', 'skill', 'proficiency'], true)) {
            throw new ApiProblem('invalid_request', 'Неизвестное развитие.', 400);
        }
        $targetId = FieldValues::integer($body['target_id'] ?? null, 'Выбор', 1, PHP_INT_MAX);
        return (new CharacterOperation($this->db))->run($id, $actor, 'character_progression', $body,
            function (array $character) use ($actor): void {
                (new CharacterVisibility())->assertVisible($character, $actor);
                if ((int) $character['user_id'] !== (int) $actor->id) {
                    throw new ApiProblem('progression_forbidden', 'Развивать можно только своего персонажа.', 403);
                }
            },
            function (array $character) use ($id, $kind, $targetId, $body): array {
                if ($character['status'] !== 'active') throw new ApiProblem('progression_closed', 'Персонаж ещё не активен.', 409);
                return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $kind, $targetId, $body): array {
                    if (!is_string($body['rules_hash'] ?? null) || !hash_equals($catalog['rules_hash'], $body['rules_hash'])) {
                        throw new ApiProblem('progression_rules_changed', 'Правила развития изменились. Обнови анкету.', 409);
                    }
                    $rules = DevelopmentRules::defaults();
                    $snapshot = (new CharacterSnapshotBuilder(new CharacterStateReader($this->db), $rules))->build($id);
                    $options = (new ProgressionOptions())->evaluate($snapshot, $catalog['definitions'], $catalog['configuration']);
                    $group = ['attribute' => 'attributes', 'skill' => 'skills', 'proficiency' => 'proficiencies'][$kind];
                    $option = array_column($options[$group], null, 'id')[$targetId] ?? null;
                    if (!$option || !$option['allowed']) {
                        throw new ApiProblem($option['code'] ?? 'progression_unavailable',
                            $option['reason'] ?? 'Этот выбор недоступен.', 409);
                    }
                    if ($kind === 'attribute') $this->attribute($id, $option, $snapshot->attributes, $catalog['definitions'], $rules);
                    else $this->training($id, $kind, $option, $catalog);
                    return ['fields' => [], 'event' => 'character_developed',
                        'payload' => ['kind' => $kind, 'target_id' => $targetId, 'name' => $option['name'],
                            'level' => $kind === 'attribute' ? $option['level'] + 1 : ($option['target_level'] ?? null),
                            'experience_spent' => $option['cost'] ?? 0]];
                });
            });
    }

    private function attribute(int $id, array $option, array $attributes, array $definitions, DevelopmentRules $rules): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $attribute = array_column($attributes, null, 'id')[$option['id']];
        $slug = array_search($attribute, $attributes, true);
        $level = $option['level'] + 1;
        (new \Maxcuso\Roleplay\Character\State\CharacterBalances($this->db))->adjust($id, 'experience_active', -$option['cost']);
        // Расовый бонус остаётся тем же. Накопленный опыт не уменьшается.
        $this->db->table('roleplay_character_attributes')->where('character_id', $id)->where('attribute_id', $option['id'])
            ->increment('base_value', 1, ['updated_at' => $now]);
        foreach (['skill' => $rules->skillChoiceLevels($slug), 'proficiency' => $rules->proficiencyChoiceLevels($slug)] as $kind => $levels) {
            if (in_array($level, $levels, true)) {
                $this->db->table('roleplay_character_choices')->insert([
                    'character_id' => $id, 'attribute_id' => $option['id'], 'kind' => $kind, 'threshold' => $level,
                    'target_id' => null, 'target_level' => null,
                ]);
            }
        }
        foreach ($rules->automaticProficienciesReached($slug, $level) as $key) {
            $found = null;
            foreach ($definitions['proficiencies'] as $candidate) {
                if ($candidate['slug'] === $key && $candidate['attribute_id'] === $option['id']) $found = $candidate;
            }
            if (!$found) throw new ApiProblem('automatic_proficiency_missing', 'Не настроено автоматическое владение.', 409);
            $owned = $this->db->table('roleplay_character_weapon_proficiencies')->where('character_id', $id)
                ->where('proficiency_id', $found['id'])->exists();
            if (!$owned) {
                if (!$found['enabled']) throw new ApiProblem('automatic_proficiency_disabled', 'Автоматическое владение выключено.', 409);
                (new CharacterGrants($this->db))->proficiency($id, $found['id']);
            }
        }
    }

    private function training(int $id, string $kind, array $option, array $catalog): void
    {
        $grants = new CharacterGrants($this->db);
        if ($kind === 'skill') {
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('roleplay_character_skills')->updateOrInsert(
                ['character_id' => $id, 'skill_id' => $option['id']],
                ['level' => $option['target_level'], 'updated_at' => $now]
                    + ($option['level'] === 0 ? ['created_at' => $now] : []));
            if ($option['grant_perk']) {
                $slug = $catalog['definitions']['attributes'][$option['attribute_id']]['slug'];
                $grants->perk($id, $option['perk_id'], $catalog['configuration']['perk_sources']['skill'],
                    ['skill_id' => $option['id'], 'skill_name' => $option['name'],
                        'skill_level' => $option['target_level'], 'attribute_slug' => $slug]);
            }
        } else $grants->proficiency($id, $option['id']);
        if ($option['consume_choice']) {
            $choice = $this->db->table('roleplay_character_choices')->where('character_id', $id)
                ->where('attribute_id', $option['attribute_id'])->where('kind', $kind)
                ->whereNull('target_id')->orderBy('threshold')->lockForUpdate()->first();
            if (!$choice) throw new ApiProblem('progression_unavailable', 'Выбор уже израсходован.', 409);
            $this->db->table('roleplay_character_choices')->where('id', $choice->id)->update([
                'target_id' => $option['id'], 'target_level' => $kind === 'skill' ? $option['target_level'] : null,
            ]);
        }
    }
}