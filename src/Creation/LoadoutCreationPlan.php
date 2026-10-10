<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

/** Из прежнего LoadoutPreview сохранены группы и суммирование вещей; всё остаётся в памяти. */
final class LoadoutCreationPlan
{
    public function build(array $payload, array $catalog, array $configuration): array
    {
        $errors = [];
        $items = [];
        $manualKeys = array_column(array_filter($configuration['loadout'], fn (array $group) => !$group['automatic']), 'key');
        if (array_diff(array_keys($payload['loadout']), $manualKeys)) {
            $errors[] = 'Есть выбор из неизвестной или автоматической группы снаряжения.';
        }
        foreach ($configuration['loadout'] as $group) {
            $selected = $group['automatic'] ? [$group['options'][0]['key']] : ($payload['loadout'][$group['key']] ?? []);
            if (count($selected) < $group['min'] || count($selected) > $group['max']
                || array_diff($selected, array_column($group['options'], 'key'))) {
                $errors[] = 'Проверь выбор снаряжения: '.$group['name'].'.';
                continue;
            }
            foreach ($group['options'] as $option) {
                if (!in_array($option['key'], $selected, true)) continue;
                foreach ($option['items'] as $entry) {
                    $id = $entry['item_id'];
                    // Стартовое оружие можно получить без владения; оно проверяется при расчёте атаки.
                    $definition = $catalog['items'][$id] ?? null;
                    if ($definition === null || !$definition['enabled']
                        || !($catalog['item-types'][$definition['item_type_id']]['enabled'] ?? false)) {
                        $errors[] = 'Стартовая вещь или её тип выключены.';
                        continue;
                    }
                    $quantity = ($items[$id]['quantity'] ?? 0) + $entry['quantity'];
                    if ($quantity > 1000000) {
                        $errors[] = 'Превышено количество стартовых вещей.';
                        continue;
                    }
                    $items[$id] = ['id' => $id, 'name' => $definition['name'], 'quantity' => $quantity,
                        'max_stack' => $definition['max_stack']];
                }
            }
        }
        $rows = 0;
        foreach ($items as $item) {
            $rows += intdiv($item['quantity'] - 1, $item['max_stack']) + 1;
        }
        if ($rows > 1000) $errors[] = 'Стартовый инвентарь превышает 1000 стопок.';
        return ['items' => array_values($items), 'errors' => $errors];
    }
}