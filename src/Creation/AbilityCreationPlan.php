<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

/** Перенос расовых выдач и выбора заклинаний: определения читаются из новых каталогов. */
final class AbilityCreationPlan
{
    public function __construct(private readonly ?\Maxcuso\Roleplay\Rules\DevelopmentConfiguration $development = null) {}
    public function racialPerks(array $payload, array $race, array $catalog): array
    {
        $errors = [];
        $rules = $race['rules'];
        $selected = $payload['perk_ids'];
        if (count($selected) !== $rules['perk_choice_count'] || array_diff($selected, $rules['perk_choice_ids'])) {
            $errors[] = 'Проверьте количество и доступность выбранных расовых перков';
        }
        $ids = array_values(array_unique(array_merge($rules['fixed_perk_ids'], $selected)));
        $grants = [];
        foreach ($ids as $id) {
            if (!isset($catalog['perks'][$id]) || !$catalog['perks'][$id]['enabled']) {
                $errors[] = 'Выбранный расовый перк больше нельзя получить. Выберите другой доступный перк';
                continue;
            }
            $grants[] = ['id' => $id, 'kind' => 'race',
                'context' => ['race_id' => $race['id'], 'race_name' => $race['name']]];
        }
        return ['perk_grants' => $grants, 'errors' => $errors];
    }

    /** Один источник на выдачу. Сначала расовые перки, затем новые перки навыковых уровней. */
    public function perkSources(array $grants, array $catalog, array $configuration): array
    {
        $errors = [];
        $result = [];
        foreach ($grants as $grant) {
            if (isset($result[$grant['id']])) {
                continue;
            }
            $sourceId = $configuration['perk_sources'][$grant['kind']] ?? null;
            if ($sourceId === null || !isset($catalog['sources'][$sourceId]) || !$catalog['sources'][$sourceId]['enabled']) {
                $errors[] = 'Не удалось определить источник перка: '.$grant['kind'].'. Обратитесь к администратору';
                continue;
            }
            // Обычная редкость относится к стартовой выдаче, не переписывает общий каталог.
            $result[$grant['id']] = $grant + ['source_id' => $sourceId, 'rarity' => 'common'];
        }
        return ['perks' => array_values($result), 'errors' => $errors];
    }

    public function spells(array $payload, array $race, array $attributes, array $catalog, array $configuration): array
    {
        $errors = [];
        $rules = $race['rules'];
        $fixed = $rules['fixed_spell_ids'];
        $racialPool = $rules['spell_choice_ids'];
        foreach ($catalog['spells'] as $spell) {
            if (in_array($spell['magic_path_id'], $rules['spell_choice_path_ids'], true)) {
                $racialPool[] = $spell['id'];
            }
        }
        $racialPool = array_values(array_diff(array_unique($racialPool), $fixed));
        $racialPool = array_values(array_filter($racialPool, fn (int $id) => $this->spellAvailable($id, $catalog)));
        $racialSelected = $payload['racial_spell_ids'];
        if (count($racialSelected) !== $rules['spell_choice_count'] || array_diff($racialSelected, $racialPool)) {
            $errors[] = 'Проверьте количество и доступность выбранных расовых заклинаний';
        }
        $circle = \Maxcuso\Roleplay\Rules\StatRules::defaults($this->development)->magicCircleAt($attributes['magic']['level'] ?? 0);
        $ordinaryCount = $circle >= 1 ? $configuration['starting_spell_count'] : 0;
        $ordinaryPool = [];
        foreach ($catalog['spells'] as $spell) {
            // Расовые выдачи настраиваются отдельно и не зависят от этого признака.
            if ($spell['is_starting'] && $spell['level'] === 1 && $this->spellAvailable($spell['id'], $catalog)
                && !in_array($spell['id'], array_merge($fixed, $racialSelected), true)) {
                $ordinaryPool[] = $spell['id'];
            }
        }
        if (count($payload['spell_ids']) !== $ordinaryCount || array_diff($payload['spell_ids'], $ordinaryPool)) {
            $errors[] = 'Выберите '.$ordinaryCount.' разных обычных стартовых заклинаний I круга';
        }
        $ids = array_merge($fixed, $racialSelected, $payload['spell_ids']);
        if (count(array_unique($ids)) !== count($ids)) {
            $errors[] = 'Одно заклинание выбрано дважды. Замените его в одном из стартовых выборов';
        }
        $spells = [];
        foreach (array_unique($ids) as $id) {
            if (!$this->spellAvailable($id, $catalog)) {
                $errors[] = 'Выбранное заклинание или его путь больше недоступны. Выберите другое доступное заклинание';
                continue;
            }
            // Для расовых заклинаний, включая выбор, не проверяем Магию и доступный круг.
            $spells[] = $catalog['spells'][$id];
        }
        return ['spells' => $spells, 'errors' => $errors,
            'spell_choices' => ['ordinary_count' => $ordinaryCount, 'ordinary_ids' => $ordinaryPool,
                'racial_count' => $rules['spell_choice_count'], 'racial_ids' => $racialPool]];
    }

    private function spellAvailable(int $id, array $catalog): bool
    {
        $spell = $catalog['spells'][$id] ?? null;
        return $spell !== null && $spell['enabled'] && ($catalog['paths'][$spell['magic_path_id']]['enabled'] ?? false);
    }
}
