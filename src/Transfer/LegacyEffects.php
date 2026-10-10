<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

/** Старый эффект мог содержать много модификаторов. Не скрываем неоднозначное преобразование. */
final class LegacyEffects
{
    public function build(array $legacy): array
    {
        $definitions = $links = [];
        $stats = array_column($legacy['stats'] ?? [], 'slug', 'id');
        foreach ($legacy['effects'] ?? [] as $effect) {
            $modifiers = array_values(array_filter($legacy['effect_modifiers'] ?? [], fn ($row) => $row['effect_id'] === $effect['id']));
            if (count($modifiers) > 1) throw new \DomainException('Эффект '.$effect['id'].' содержит несколько модификаторов: нужна явная карта разделения.');
            $modifier = $modifiers[0] ?? null;
            $input = ['name' => $effect['name'], 'description' => $effect['description'], 'enabled' => (bool) $effect['enabled'],
                'sort_order' => 0, 'icon' => null, 'mechanic' => 'text', 'operation' => 'add'];
            if ($modifier !== null) {
                if ($modifier['stat_id'] !== null) {
                    $input['mechanic'] = 'stat';
                    $input['stat'] = $stats[$modifier['stat_id']] ?? throw new \DomainException('Неизвестная цель старого эффекта.');
                } elseif ($modifier['attribute_id'] !== null) {
                    $input['mechanic'] = 'attribute';
                    $input['attribute_id'] = (int) $modifier['attribute_id'];
                } else throw new \DomainException('Неизвестный модификатор эффекта.');
                $input['operation'] = match ($modifier['operation']) {
                    'add_flat' => 'add', 'add_percent' => 'percent',
                    default => throw new \DomainException('Неизвестная операция эффекта.'),
                };
            }
            $value = $modifier === null ? null : (float) $modifier['value'];
            if ($value !== null && floor($value) === $value) $value = (int) $value;
            $definitions[] = ['id' => (int) $effect['id'], 'slug' => $effect['slug'], 'input' => $input];
            foreach (['perk' => 'perks', 'item' => 'items'] as $owner => $catalog) {
                foreach ($legacy[$owner.'_effects'] ?? [] as $link) {
                    if ($link['effect_id'] !== $effect['id']) continue;
                    $links[] = ['owner' => $catalog, 'id' => (int) $link[$owner.'_id'], 'link' => [
                        'effect_id' => (int) $effect['id'],
                        'value' => $value,
                        'condition' => $modifier['condition'] ?? 'always', 'condition_text' => null, 'scope' => 'all',
                    ]];
                }
            }
        }
        return compact('definitions', 'links');
    }
}