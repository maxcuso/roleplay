<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Character\State\CharacterSnapshot;
use Maxcuso\Roleplay\Character\Calculation\CharacterCalculationBuilder;

/** Виртуальное состояние использует общий расчёт профиля. Ни персонаж, ни выдачи в БД не создаются. */
final class CreationReview
{
    public function __construct(private readonly ?\Maxcuso\Roleplay\Rules\DevelopmentConfiguration $configuration = null) {}
    public function build(array $payload, array $catalog, array $plan, int $revision): ?array
    {
        // Полный итог строится только для допустимого плана, в том числе не более 1000 стопок.
        if (!$plan['valid']) return null;
        $race = $catalog['races'][$payload['race_id']];
        $attributes = $plan['attributes'];
        foreach ($attributes as &$attribute) {
            $attribute['sources'] = [
                ['value' => $attribute['base_value'], 'source' => ['kind' => 'attribute', 'label' => $attribute['name']]],
                ['value' => $attribute['racial_bonus'], 'source' => ['kind' => 'race', 'id' => $race['id'], 'label' => $race['name']]],
            ];
        }
        unset($attribute);
        $skills = array_map(fn (array $row) => $row + ['definition' => $catalog['skills'][$row['id']]], $plan['skills']);
        $proficiencies = array_map(fn (array $row) => $row + ['definition' => $catalog['proficiencies'][$row['id']]], $plan['proficiencies']);
        $perks = [];
        foreach ($plan['perks'] as $index => $grant) {
            $definition = $catalog['perks'][$grant['id']];
            $perks[] = ['id' => $grant['id'], 'grant_id' => $index + 1, 'name' => $definition['name'],
                'definition' => $definition, 'rarity' => $grant['rarity'],
                'source' => ['id' => $grant['source_id'], 'definition' => $catalog['sources'][$grant['source_id']],
                    'context' => $grant['context']]];
        }
        $spells = [];
        foreach ($plan['spells'] as $index => $spell) {
            $spells[] = ['id' => $spell['id'], 'grant_id' => $index + 1, 'name' => $spell['name'],
                'definition' => $spell, 'path' => $catalog['paths'][$spell['magic_path_id']]];
        }
        $inventory = [];
        foreach ($plan['items'] as $item) {
            $definition = $catalog['items'][$item['id']];
            $remaining = $item['quantity'];
            // Отображаем те же стопки, которые CreationGrants сохранит при создании.
            while ($remaining > 0) {
                $quantity = min($remaining, $definition['max_stack']);
                $inventory[] = ['id' => count($inventory) + 1, 'item_id' => $item['id'], 'name' => $item['name'],
                    'quantity' => $quantity, 'equipped' => false, 'weight' => $definition['weight'] ?? 0,
                    'definition' => $definition, 'type' => $catalog['item-types'][$definition['item_type_id']]];
                $remaining -= $quantity;
            }
        }
        $snapshot = new CharacterSnapshot($revision,
            ['first_name' => $payload['first_name'], 'last_name' => $payload['last_name'], 'age' => $payload['age'],
                'avatar' => $payload['avatar'] ?? null, 'race' => $race, 'status' => 'creating', 'rank' => null],
            $attributes, $skills, $proficiencies, $perks, $spells, $inventory, [], [],
            $plan['balances'], $plan['choices']);
        return ['snapshot' => $snapshot, 'calculation' => CharacterCalculationBuilder::defaults($this->configuration)->calculate($snapshot)];
    }
}
