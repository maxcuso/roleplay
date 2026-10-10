<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\SkillEligibility;
use Maxcuso\Roleplay\Rules\ProficiencyEligibility;

/** Варианты для интерфейса строим теми же правилами, что подтверждение. Клиент не считает пороги. */
final class CreationOptions
{
    public function build(array $payload, array $catalog, array $plan): array
    {
        $steps = ['basics', 'attributes', 'training', 'perks', 'spells', 'loadout'];
        $validity = [];
        $previous = true;
        foreach ($steps as $step) {
            $previous = $previous && empty($plan['errors'][$step]);
            $validity[$step] = $previous;
        }
        $validity['review'] = $plan['valid'];
        $result = ['step_validity' => $validity, 'training_slots' => [], 'racial_proficiency_ids' => []];
        if (!$validity['attributes']) return $result;

        $rules = DevelopmentRules::defaults();
        $builder = new TrainingCreationPlan($rules);
        $race = $catalog['races'][$payload['race_id']];
        $racialPerks = array_unique(array_merge($race['rules']['fixed_perk_ids'], $payload['perk_ids']));
        // Сначала определяем автоматические выдачи, чтобы не предлагать их второй раз за расу.
        $empty = $payload;
        $empty['skills'] = $empty['proficiencies'] = $empty['racial_proficiency_ids'] = [];
        $automatic = $builder->build($empty, $catalog, $plan['attributes'], $racialPerks, $race);
        $result['racial_proficiency_ids'] = array_values(array_diff(
            TrainingCreationPlan::racialPool($race, $catalog),
            array_column($automatic['proficiencies'], 'id'), $payload['proficiencies']));

        foreach ($plan['attributes'] as $attribute) {
            foreach (['skill' => 'skills', 'proficiency' => 'proficiencies'] as $kind => $field) {
                $levels = $kind === 'skill' ? $rules->skillChoiceLevels($attribute['slug'])
                    : $rules->proficiencyChoiceLevels($attribute['slug']);
                $levels = array_values(array_filter($levels, fn (int $level) => $level <= $attribute['level']));
                $positions = [];
                foreach ($payload[$field] as $index => $id) {
                    if (($catalog[$field][$id]['attribute_id'] ?? null) === $attribute['id']) $positions[] = $index;
                }
                foreach ($levels as $ordinal => $threshold) {
                    $index = $positions[$ordinal] ?? null;
                    $prefix = $payload;
                    // Редактируемый выбор оценивается до собственного применения, а не по финальному рангу.
                    $prefix[$field] = $index === null ? $payload[$field] : array_slice($payload[$field], 0, $index);
                    $before = $builder->build($prefix, $catalog, $plan['attributes'], $racialPerks, $race);
                    $remaining = count(array_filter($before['choices'], fn (array $choice) =>
                        $choice['attribute_id'] === $attribute['id'] && $choice['kind'] === $kind
                        && $choice['target_id'] === null));
                    $owned = array_unique(array_merge($racialPerks, array_column($before['perk_grants'], 'id')));
                    $learned = array_column($before[$field], null, 'id');
                    $options = [];
                    foreach ($catalog[$field] as $candidate) {
                        if ($candidate['attribute_id'] !== $attribute['id']) continue;
                        $check = $kind === 'skill'
                            ? (new SkillEligibility($rules))->evaluate($candidate, $attribute, $attribute['level'],
                                $learned[$candidate['id']]['level'] ?? 0, $remaining, array_values($catalog['perks']), $owned)
                            : (new ProficiencyEligibility($rules))->evaluate($candidate, $attribute, $attribute['level'],
                                isset($learned[$candidate['id']]), $remaining);
                        if ($check['allowed']) $options[] = ['id' => $candidate['id'], 'name' => $candidate['name'],
                            'level' => $check['target_level'] ?? null];
                    }
                    $result['training_slots'][] = ['attribute_id' => $attribute['id'], 'kind' => $kind,
                        'threshold' => $threshold, 'ordinal' => $ordinal, 'payload_index' => $index,
                        'selected_id' => $index === null ? null : $payload[$field][$index], 'options' => $options];
                }
            }
        }
        return $result;
    }
}
