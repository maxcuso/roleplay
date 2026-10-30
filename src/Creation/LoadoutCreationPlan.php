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
            $errors[] = 'В выборе снаряжения есть неизвестная группа или группа с автоматической выдачей. Уберите этот выбор';
        }
        foreach ($configuration['loadout'] as $group) {
            $selected = $group['automatic'] ? [$group['options'][0]['key']] : ($payload['loadout'][$group['key']] ?? []);
            if (count($selected) < $group['min'] || count($selected) > $group['max']
                || array_diff($selected, array_column($group['options'], 'key'))) {
                $errors[] = 'Проверьте количество и доступность снаряжения в группе «'.$group['name'].'»';
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
                        $errors[] = 'Выбранный предмет или его тип больше недоступны. Выберите другой доступный предмет';
                        continue;
                    }
                    $quantity = ($items[$id]['quantity'] ?? 0) + $entry['quantity'];
                    if ($quantity > 1000000) {
                        $errors[] = 'Выбрано слишком много стартовых предметов. Уменьшите их количество';
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
        if ($rows > 1000) $errors[] = 'Не удалось выдать стартовое снаряжение: количество стопок превышает 1000. Обратитесь к администратору';
        return ['items' => array_values($items), 'errors' => $errors];
    }
}