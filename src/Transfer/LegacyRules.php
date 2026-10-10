<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

/** Преобразует прежние JSON-правила в настройки, не переносит универсальный исполнитель. */
final class LegacyRules
{
    public function build(array $legacy): array
    {
        $attributes = array_column($legacy['attributes'], 'slug', 'id');
        $races = $levels = $warnings = [];
        foreach ($legacy['races'] as $race) $races[$race['id']] = [];
        foreach ($legacy['race_attribute_bonuses'] ?? [] as $bonus) {
            $slug = $attributes[$bonus['attribute_id']] ?? throw new \DomainException('Неизвестная характеристика расового бонуса.');
            $races[$bonus['race_id']]['fixed_bonuses'][$slug] = (int) $bonus['value'];
        }
        $configuration = require __DIR__.'/../../resources/rules/creation.php';
        $spellPool = [];
        // Сначала общий список: старый starting_spell_bonus ссылался именно на него.
        foreach ($legacy['rules'] ?? [] as $rule) {
            if ($rule['enabled'] && $rule['rule_type'] === 'starting_spell_choices') {
                $spellPool = $this->config($rule)['spellIds'] ?? [];
            }
        }
        foreach ($legacy['rules'] ?? [] as $rule) {
            if (!$rule['enabled']) continue;
            $config = $this->config($rule);
            $scope = $rule['scope_type'];
            $id = (int) $rule['scope_id'];
            $type = $rule['rule_type'];
            if ($scope === 'system') {
                switch ($type) {
                    case 'starting_attribute_distribution': $configuration['attribute_distribution'] = $config['values']; break;
                    case 'starting_spell_choices':
                        $configuration['starting_spell_count'] = $config['count'];
                        $warnings[] = 'Обычный выбор заклинаний использует новые правила Магии и включённые заклинания I круга; прежний список сохранён в исходном экспорте.';
                        break;
                    case 'starting_loadout': $loadout = $config; break;
                    case 'starting_currency':
                        if (($config['currencyId'] ?? null) !== 1) throw new \DomainException('Неизвестная стартовая валюта.');
                        $configuration['starting_gold'] = $config['amount'];
                        break;
                    case 'skill_choice':
                    case 'choose_proficiency':
                        // Пороги согласованы заново и находятся в development.php.
                        $warnings[] = 'Прежний порог '.$rule['slug'].' заменён согласованной новой системой развития.';
                        break;
                    default: throw new \DomainException('Неподдерживаемое системное правило: '.$rule['slug']);
                }
            } elseif ($scope === 'skill' && $type === 'grant_perk') {
                $level = $config['minLevel'] ?? 1;
                if ($level < 1 || $level > 3 || count($config['perkIds'] ?? []) !== 1 || isset($levels[$id][$level])) {
                    throw new \DomainException('Неоднозначный перк уровня: '.$rule['slug']);
                }
                $levels[$id][$level] = $config['perkIds'][0];
            } elseif ($scope === 'race' && isset($races[$id])) {
                switch ($type) {
                    case 'choose_attribute_bonus':
                        if (($config['unique'] ?? true) !== true || isset($races[$id]['attribute_count'])) {
                            throw new \DomainException('Неоднозначные расовые бонусы: '.$rule['slug']);
                        }
                        $races[$id]['choice_attributes'] = array_map(
                            fn ($id) => $attributes[$id] ?? throw new \DomainException('Неизвестная характеристика.'), $config['allowedAttributeIds']);
                        $races[$id]['attribute_bonus'] = $config['bonus'];
                        $races[$id]['attribute_count'] = $config['choose'];
                        break;
                    case 'grant_perk':
                        if (($config['minLevel'] ?? 1) !== 1) throw new \DomainException('Расовый перк с условием уровня требует ручного преобразования.');
                        $races[$id]['fixed_perk_ids'] = array_merge($races[$id]['fixed_perk_ids'] ?? [], $config['perkIds']);
                        break;
                    case 'choose_perk':
                        $this->singleChoice($races[$id], 'perk', $config['perkIds'], $config['count'] ?? $config['choose']);
                        break;
                    case 'grant_racial_spells':
                        $races[$id]['fixed_spell_ids'] = array_merge($races[$id]['fixed_spell_ids'] ?? [], $config['spellIds']);
                        break;
                    case 'starting_spell_bonus':
                        $this->singleChoice($races[$id], 'spell', $spellPool, $config['count']);
                        $warnings[] = 'Расовый выбор '.$id.' перенесён отдельно от обычного и обходит требование Магии.';
                        break;
                    case 'choose_racial_spells':
                        $this->singleChoice($races[$id], 'spell', $config['spellIds'] ?? [], $config['count']);
                        if (isset($config['magicPathId'])) $races[$id]['spell_choice_path_ids'] = [$config['magicPathId']];
                        break;
                    default: throw new \DomainException('Неподдерживаемое расовое правило: '.$rule['slug']);
                }
            } else {
                throw new \DomainException('Неизвестное правило: '.$rule['slug']);
            }
        }
        foreach ($legacy['perk_sources'] as $source) {
            if (in_array($source['slug'], ['race', 'skill'], true)) $configuration['perk_sources'][$source['slug']] = (int) $source['id'];
        }
        foreach ($legacy['starter_loadout_groups'] ?? [] as $group) {
            if (!$group['enabled'] || !in_array($group['id'], $loadout['groupIds'] ?? [], true)) continue;
            $options = [];
            foreach ($legacy['starter_loadout_options'] ?? [] as $option) {
                if (!$option['enabled'] || $option['group_id'] !== $group['id']) continue;
                $items = [];
                foreach ($legacy['starter_loadout_option_items'] ?? [] as $item) {
                    if ($item['option_id'] === $option['id']) $items[] = [
                        'item_id' => (int) $item['item_id'], 'quantity' => (int) $item['quantity'],
                    ];
                }
                $options[] = ['key' => 'legacy-option-'.$option['id'], 'name' => $option['name'], 'items' => $items];
            }
            $configuration['loadout'][] = ['key' => $group['slug'], 'name' => $group['name'],
                'automatic' => in_array($group['id'], $loadout['automaticGroupIds'] ?? [], true),
                'min' => (int) $group['min_choices'], 'max' => (int) $group['max_choices'], 'options' => $options];
        }
        $warnings[] = 'Создание оставлено выключенным: нужно заполнить неполные навыки и выбрать тег литературных анкет.';
        return compact('races', 'levels', 'configuration', 'warnings');
    }

    private function config(array $rule): array
    {
        if ((int) $rule['config_version'] !== 1) throw new \DomainException('Неизвестная версия правила.');
        return json_decode($rule['config'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function singleChoice(array &$race, string $type, array $ids, int $count): void
    {
        if (isset($race[$type.'_choice_count'])) throw new \DomainException('Несколько независимых расовых групп требуют ручного объединения.');
        $race[$type.'_choice_ids'] = $ids;
        $race[$type.'_choice_count'] = $count;
    }
}