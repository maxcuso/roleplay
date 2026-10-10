<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Application\ApplicationConfiguration;

/** Серверные настройки, не поля игрока. Конкретные наборы не зашиты в классы или миграцию. */
final class CreationConfiguration
{
    public function normalize(array $input): array
    {
        $defaults = require __DIR__.'/../../resources/rules/creation.php';
        FieldValues::allowed($input, array_keys($defaults));
        $input += $defaults;
        $distribution = $input['attribute_distribution'];
        if (!is_array($distribution) || !array_is_list($distribution) || count($distribution) !== 4) {
            FieldValues::invalid('Стартовое распределение должно содержать четыре значения.');
        }
        foreach ($distribution as $value) {
            FieldValues::integer($value, 'Стартовая характеристика', 1, 25);
        }
        $sources = $input['perk_sources'];
        if (!is_array($sources)) {
            FieldValues::invalid('Нужен объект привязок источников.');
        }
        FieldValues::allowed($sources, ['race', 'skill']);
        foreach ($sources as $id) {
            FieldValues::integer($id, 'Источник перка', 1, PHP_INT_MAX);
        }
        $groups = $input['loadout'];
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 100) {
            FieldValues::invalid('Нужен список стартовых групп.');
        }
        $knownGroups = [];
        foreach ($groups as &$group) {
            if (!is_array($group)) {
                FieldValues::invalid('Некорректная стартовая группа.');
            }
            FieldValues::allowed($group, ['key', 'name', 'automatic', 'min', 'max', 'options']);
            $this->key($group['key'] ?? null);
            if (isset($knownGroups[$group['key']])) {
                FieldValues::invalid('Ключ стартовой группы повторяется.');
            }
            $knownGroups[$group['key']] = true;
            $group['name'] = FieldValues::text($group['name'] ?? null, 'Название группы', 100, true);
            $group['automatic'] = FieldValues::boolean($group['automatic'] ?? false, 'Автоматическая группа');
            $group['min'] = FieldValues::integer($group['min'] ?? 1, 'Минимум выборов', 0, 100);
            $group['max'] = FieldValues::integer($group['max'] ?? 1, 'Максимум выборов', $group['min'], 100);
            if (!is_array($group['options'] ?? null) || !array_is_list($group['options']) || count($group['options']) > 100) {
                FieldValues::invalid('Нужен список вариантов набора.');
            }
            if (count($group['options']) < $group['min']
                || ($group['automatic'] && (count($group['options']) !== 1 || $group['min'] !== 1 || $group['max'] !== 1))) {
                FieldValues::invalid('Не хватает вариантов или некорректна автоматическая группа.');
            }
            $knownOptions = [];
            foreach ($group['options'] as &$option) {
                if (!is_array($option)) FieldValues::invalid('Некорректный вариант набора.');
                FieldValues::allowed($option, ['key', 'name', 'items']);
                $this->key($option['key'] ?? null);
                if (isset($knownOptions[$option['key']])) FieldValues::invalid('Ключ варианта повторяется.');
                $knownOptions[$option['key']] = true;
                $option['name'] = FieldValues::text($option['name'] ?? null, 'Название варианта', 100, true);
                if (!is_array($option['items'] ?? null) || !array_is_list($option['items']) || count($option['items']) > 100) {
                    FieldValues::invalid('Нужен список вещей варианта.');
                }
                foreach ($option['items'] as $item) {
                    if (!is_array($item)) FieldValues::invalid('Некорректная вещь набора.');
                    FieldValues::allowed($item, ['item_id', 'quantity']);
                    FieldValues::integer($item['item_id'] ?? null, 'Стартовая вещь', 1, PHP_INT_MAX);
                    FieldValues::integer($item['quantity'] ?? null, 'Количество', 1, 1000000);
                }
            }
            unset($option);
        }
        unset($group);
        return [
            'enabled' => FieldValues::boolean($input['enabled'], 'Создание включено'),
            'max_characters' => FieldValues::integer($input['max_characters'], 'Лимит персонажей', 1, 100),
            'attribute_distribution' => $distribution,
            'starting_spell_count' => FieldValues::integer($input['starting_spell_count'], 'Стартовые заклинания', 0, 100),
            'starting_gold' => FieldValues::integer($input['starting_gold'], 'Стартовое золото', 0, 9007199254740991),
            'starting_experience' => FieldValues::integer($input['starting_experience'], 'Стартовый опыт', 0, 9007199254740991),
            'perk_sources' => $sources, 'loadout' => $groups,
            'application' => (new ApplicationConfiguration())->normalize($input['application']),
        ];
    }

    private function key(mixed $key): void
    {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $key)) {
            FieldValues::invalid('Некорректный ключ стартовой группы или варианта.');
        }
    }
}