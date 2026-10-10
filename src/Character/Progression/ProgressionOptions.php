<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Progression;

use Maxcuso\Roleplay\Character\State\CharacterSnapshot;
use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\ExperienceCosts;
use Maxcuso\Roleplay\Rules\SkillEligibility;
use Maxcuso\Roleplay\Rules\ProficiencyEligibility;

/** Одна проверка доступности для стрелок в профиле и для сохранения команды.
 * Использует приобретённый level, не итоговую характеристику с эффектами.
 */
final class ProgressionOptions
{
    public function evaluate(CharacterSnapshot $snapshot, array $definitions, array $configuration): array
    {
        $rules = DevelopmentRules::defaults();
        $attributes = [];
        foreach ($snapshot->attributes as $slug => $attribute) {
            $cost = $attribute['level'] < $rules->maximumAttributeLevel()
                ? ExperienceCosts::forLevel($attribute['level'] + 1) : null;
            $enabled = $definitions['attributes'][$attribute['id']]['enabled'] ?? false;
            $attributes[] = ['id' => $attribute['id'], 'name' => $attribute['name'], 'icon' => $attribute['icon'],
                'level' => $attribute['level'], 'cost' => $cost,
                'allowed' => $enabled && $cost !== null && ($snapshot->balances['experience_active'] ?? 0) >= $cost];
        }
        $byId = array_column($snapshot->attributes, null, 'id');
        $skills = [];
        $ownedSkills = array_column($snapshot->skills, 'level', 'id');
        $sourceId = $configuration['perk_sources']['skill'] ?? null;
        $sourceAvailable = $sourceId && ($definitions['sources'][$sourceId]['enabled'] ?? false);
        foreach ($definitions['skills'] as $skill) {
            $attribute = $byId[$skill['attribute_id']] ?? null;
            if (!$attribute) continue;
            $definition = $definitions['attributes'][$attribute['id']];
            $result = (new SkillEligibility($rules))->evaluate($skill, $definition, $attribute['level'],
                $ownedSkills[$skill['id']] ?? 0, $this->remaining($snapshot, $attribute['id'], 'skill'),
                array_values($definitions['perks']), $snapshot->ownedPerkIds());
            if ($result['allowed'] && $result['grant_perk'] && !$sourceAvailable) {
                $result = ['allowed' => false, 'code' => 'skill_source_missing', 'reason' => 'Не настроен источник навыковых перков.'];
            }
            $skills[] = ['id' => $skill['id'], 'name' => $skill['name'], 'attribute_id' => $attribute['id'],
                'level' => $ownedSkills[$skill['id']] ?? 0] + $result;
        }
        $proficiencies = [];
        foreach ($definitions['proficiencies'] as $proficiency) {
            $attribute = $byId[$proficiency['attribute_id']] ?? null;
            if (!$attribute) continue;
            $result = (new ProficiencyEligibility($rules))->evaluate($proficiency,
                $definitions['attributes'][$attribute['id']], $attribute['level'],
                in_array($proficiency['id'], $snapshot->ownedProficiencyIds(), true),
                $this->remaining($snapshot, $attribute['id'], 'proficiency'));
            $proficiencies[] = ['id' => $proficiency['id'], 'name' => $proficiency['name'], 'attribute_id' => $attribute['id']] + $result;
        }
        return ['attributes' => $attributes, 'skills' => $skills, 'proficiencies' => $proficiencies];
    }

    private function remaining(CharacterSnapshot $snapshot, int $attributeId, string $kind): int
    {
        $level = array_column($snapshot->attributes, 'level', 'id')[$attributeId];
        return count(array_filter($snapshot->choices, fn (array $choice) =>
            $choice['attribute_id'] === $attributeId && $choice['kind'] === $kind
            && $choice['threshold'] <= $level && $choice['target_id'] === null));
    }
}