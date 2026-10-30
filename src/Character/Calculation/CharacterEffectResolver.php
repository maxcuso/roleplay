<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Calculation;

use Maxcuso\Roleplay\Character\State\CharacterSnapshot;
use Maxcuso\Roleplay\Rules\EffectRules;

/** Собирает реальные выдачи и экипированные экземпляры. Текст перка не разбирается на числа. */
final class CharacterEffectResolver
{
    public function resolve(CharacterSnapshot $snapshot): array
    {
        $result = [];
        $seen = [];
        foreach ($snapshot->perks as $perk) {
            // Один перк не повторяется из-за источника выдачи или JOIN с уровнями навыков.
            if (isset($seen[$perk['id']])) continue;
            $seen[$perk['id']] = true;
            $source = ['kind' => 'perk', 'id' => $perk['grant_id'], 'definition_id' => $perk['id'],
                'label' => $perk['name'], 'obtained_from' => $perk['source']];
            $result = array_merge($result, $this->links($perk['definition'], 'perks', $source));
        }
        $seen = [];
        foreach ($snapshot->equipment as $item) {
            if (isset($seen[$item['id']])) continue;
            $seen[$item['id']] = true;
            if (!$item['definition']['is_magical']) {
                if ($item['definition']['effects'] ?? []) throw new \DomainException('У немагического предмета обнаружены эффекты.');
                continue;
            }
            // Два разных экземпляра одного предмета остаются двумя источниками.
            $source = ['kind' => 'item', 'id' => $item['id'], 'definition_id' => $item['item_id'], 'label' => $item['name']];
            $result = array_merge($result, $this->links($item['definition'], 'items', $source));
        }
        return $result;
    }

    private function links(array $owner, string $ownerKind, array $source): array
    {
        $result = [];
        foreach ($owner['effects'] ?? [] as $index => $effect) {
            EffectRules::validateLink($effect, $effect, $ownerKind);
            // Номер локален для владельца. Два одинаковых эффекта могут иметь разные условия.
            $name = $effect['name'] ?? EffectRules::mechanic($effect['mechanic'])['label'];
            $result[] = $effect + [
                'effect_id' => $index + 1, 'definition' => $effect,
                'source' => $source + ['effect_id' => $index + 1, 'effect_name' => $name],
            ];
        }
        return $result;
    }
}
