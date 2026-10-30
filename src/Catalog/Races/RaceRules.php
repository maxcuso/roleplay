<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Races;

use Maxcuso\Roleplay\Catalog\Shared\DefinitionStore;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Структура расовых выдач и выборов. Фиксированные заклинания выдаются без требования Магии 3. */
final class RaceRules
{
    public function __construct(private DefinitionStore $store) {}

    public function validate(mixed $input): array
    {
        if (!is_array($input)) FieldValues::invalid('Укажите расовые правила в виде объекта JSON');
        FieldValues::allowed($input, [
            'fixed_bonuses', 'choice_attributes', 'attribute_bonus', 'attribute_count',
            'fixed_perk_ids', 'perk_choice_ids', 'perk_choice_count',
            'fixed_spell_ids', 'spell_choice_ids', 'spell_choice_path_ids', 'spell_choice_count',
            'proficiency_choice_mode', 'proficiency_choice_ids', 'proficiency_choice_count',
        ]);
        $slugs = array_column($this->store->references('attributes'), 'slug');
        $bonuses = $input['fixed_bonuses'] ?? [];
        $choices = $input['choice_attributes'] ?? [];
        if (!is_array($bonuses) || !is_array($choices) || !array_is_list($choices)) {
            FieldValues::invalid('Проверьте фиксированные расовые бонусы и список характеристик, к которым они применяются');
        }
        foreach ($choices as $choice) {
            if (!is_string($choice)) FieldValues::invalid('Не удалось определить характеристику: вместо системного ключа передано другое значение');
        }
        if (array_diff(array_keys($bonuses), $slugs) || array_diff($choices, $slugs)) {
            FieldValues::invalid('В расовых бонусах указана неизвестная характеристика');
        }
        foreach ($bonuses as $slug => $bonus) {
            FieldValues::integer($bonus, 'Бонус характеристики', -1000, 1000);
            if ($bonus === 0) unset($bonuses[$slug]);
        }
        $result = [
            'fixed_bonuses' => (object) $bonuses,
            'choice_attributes' => array_values(array_unique($choices)),
            'attribute_bonus' => FieldValues::integer($input['attribute_bonus'] ?? 1, 'Бонус к каждой', 1, 1000),
            'attribute_count' => FieldValues::integer($input['attribute_count'] ?? 0, 'Количество характеристик', 0, count(array_unique($choices))),
        ];
        foreach (['perks' => ['fixed_perk_ids', 'perk_choice_ids'], 'spells' => ['fixed_spell_ids', 'spell_choice_ids'],
            'magic_paths' => ['spell_choice_path_ids']] as $catalog => $fields) {
            foreach ($fields as $field) {
                $ids = $input[$field] ?? [];
                if (!is_array($ids)) FieldValues::invalid('Укажите выбранные записи списком');
                $result[$field] = $this->store->requireIds($catalog, $ids);
            }
        }
        if (array_intersect($result['fixed_perk_ids'], $result['perk_choice_ids'])
            || array_intersect($result['fixed_spell_ids'], $result['spell_choice_ids'])) {
            FieldValues::invalid('Одна способность указана и как фиксированная, и как доступная на выбор. Оставьте её только в одном из списков');
        }
        $result['perk_choice_count'] = FieldValues::integer($input['perk_choice_count'] ?? 0,
            'Количество перков на выбор', 0, count($result['perk_choice_ids']));
        $spellPool = $result['spell_choice_ids'];
        foreach ($this->store->references('spells') as $spell) {
            if (in_array((int) $spell['magic_path_id'], $result['spell_choice_path_ids'], true)) $spellPool[] = (int) $spell['id'];
        }
        $spellPool = array_values(array_diff(array_unique($spellPool), $result['fixed_spell_ids']));
        $result['spell_choice_count'] = FieldValues::integer($input['spell_choice_count'] ?? 0,
            'Количество заклинаний на выбор', 0, count($spellPool));
        // Список задаёт раса, а не проверка уровня: расовое владение обходит обычные требования.
        $mode = $input['proficiency_choice_mode'] ?? 'list';
        if (!in_array($mode, ['all', 'list'], true)) FieldValues::invalid('Выберите доступный режим получения расовых владений');
        $ids = $input['proficiency_choice_ids'] ?? [];
        if (!is_array($ids)) FieldValues::invalid('Укажите расовые владения списком');
        $result['proficiency_choice_mode'] = $mode;
        $result['proficiency_choice_ids'] = $this->store->requireIds('weapon_proficiencies', $ids);
        if ($mode === 'all') $result['proficiency_choice_ids'] = [];
        $result['proficiency_choice_count'] = FieldValues::integer($input['proficiency_choice_count'] ?? 0,
            'Количество расовых владений', 0, $mode === 'all' ? 100 : count($result['proficiency_choice_ids']));
        return $result;
    }
}