<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\SkillEligibility;
use Maxcuso\Roleplay\Rules\ProficiencyEligibility;

/** Последовательный выбор использует готовые проверки обучения и не выдаёт ничего в БД. */
final class TrainingCreationPlan
{
    public function __construct(private readonly DevelopmentRules $rules) {}

    public function build(array $payload, array $catalog, array $attributes, array $ownedPerkIds, ?array $race = null): array
    {
        $errors = [];
        $skills = [];
        $proficiencies = [];
        $perks = [];
        $choices = [];
        $attributesById = array_column($attributes, null, 'id');
        foreach ($attributes as $slug => $attribute) {
            foreach (['skill' => $this->rules->skillChoiceLevels($slug),
                'proficiency' => $this->rules->proficiencyChoiceLevels($slug)] as $kind => $levels) {
                foreach ($levels as $threshold) {
                    if ($threshold <= $attribute['level']) {
                        $choices[] = ['attribute_id' => $attribute['id'], 'kind' => $kind, 'threshold' => $threshold,
                            'target_id' => null, 'target_level' => null];
                    }
                }
            }
            foreach ($this->rules->automaticProficienciesReached($slug, $attribute['level']) as $key) {
                $found = null;
                foreach ($catalog['proficiencies'] as $candidate) {
                    if ($candidate['slug'] === $key && $candidate['attribute_id'] === $attribute['id'] && $candidate['enabled']) {
                        $found = $candidate;
                        break;
                    }
                }
                if ($found === null) {
                    $errors[] = 'Не настроено автоматическое владение: '.$key.'.';
                } else {
                    $proficiencies[$found['id']] = $found;
                }
            }
        }
        // Автоматическое владение уже добавлено выше. Расовый выбор не тратит пороговые права.
        $racial = $race['rules'] ?? [];
        $pool = self::racialPool($race, $catalog);
        $selected = $payload['racial_proficiency_ids'] ?? [];
        if (count($selected) !== ($racial['proficiency_choice_count'] ?? 0) || array_diff($selected, $pool)) {
            $errors[] = 'Проверь расовый выбор владений.';
        }
        foreach ($selected as $id) {
            if (!in_array($id, $pool, true)) continue;
            if (isset($proficiencies[$id])) {
                $errors[] = 'Расовое владение уже получено автоматически.';
                continue;
            }
            $proficiencies[$id] = $catalog['proficiencies'][$id];
        }
        $skillRule = new SkillEligibility($this->rules);
        foreach ($payload['skills'] as $id) {
            $skill = $catalog['skills'][$id] ?? null;
            $attribute = $attributesById[$skill['attribute_id'] ?? 0] ?? null;
            if ($skill === null || $attribute === null) {
                $errors[] = 'Выбран неизвестный навык.';
                continue;
            }
            $slot = $this->availableChoice($choices, $attribute['id'], 'skill');
            $result = $skillRule->evaluate($skill, $attribute, $attribute['level'], $skills[$id]['level'] ?? 0,
                $slot === null ? 0 : 1, array_values($catalog['perks']), $ownedPerkIds);
            if (!$result['allowed']) {
                $errors[] = $skill['name'].': '.$result['reason'];
                continue;
            }
            $skills[$id] = ['id' => $id, 'name' => $skill['name'], 'level' => $result['target_level']];
            $choices[$slot]['target_id'] = $id;
            $choices[$slot]['target_level'] = $result['target_level'];
            if ($result['grant_perk']) {
                $perks[] = ['id' => $result['perk_id'], 'kind' => 'skill',
                    'context' => ['skill_id' => $id, 'skill_name' => $skill['name'],
                        'skill_level' => $result['target_level'], 'attribute_slug' => $attribute['slug']]];
                $ownedPerkIds[] = $result['perk_id'];
            }
        }
        $proficiencyRule = new ProficiencyEligibility($this->rules);
        foreach ($payload['proficiencies'] as $id) {
            $proficiency = $catalog['proficiencies'][$id] ?? null;
            $attribute = $attributesById[$proficiency['attribute_id'] ?? 0] ?? null;
            if ($proficiency === null || $attribute === null) {
                $errors[] = 'Выбрано неизвестное владение.';
                continue;
            }
            $slot = $this->availableChoice($choices, $attribute['id'], 'proficiency');
            $result = $proficiencyRule->evaluate($proficiency, $attribute, $attribute['level'],
                isset($proficiencies[$id]), $slot === null ? 0 : 1);
            if (!$result['allowed']) {
                $errors[] = $proficiency['name'].': '.$result['reason'];
                continue;
            }
            $proficiencies[$id] = $proficiency;
            if ($result['consume_choice']) {
                $choices[$slot]['target_id'] = $id;
            }
        }
        return ['skills' => array_values($skills), 'proficiencies' => array_values($proficiencies),
            'perk_grants' => $perks, 'choices' => $choices, 'errors' => $errors];
    }

    /** Только статус определения и расовый список. Требования характеристик здесь не применяются. */
    public static function racialPool(?array $race, array $catalog): array
    {
        $rules = $race['rules'] ?? [];
        $all = ($rules['proficiency_choice_mode'] ?? 'list') === 'all';
        return array_values(array_column(array_filter($catalog['proficiencies'], fn (array $row) =>
            $row['enabled'] && ($all || in_array($row['id'], $rules['proficiency_choice_ids'] ?? [], true))), 'id'));
    }

    private function availableChoice(array $choices, int $attributeId, string $kind): ?int
    {
        foreach ($choices as $index => $choice) {
            if ($choice['attribute_id'] === $attributeId && $choice['kind'] === $kind && $choice['target_id'] === null) {
                return $index;
            }
        }
        return null;
    }
}