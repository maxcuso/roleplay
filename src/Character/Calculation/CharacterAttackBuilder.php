<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Calculation;

use Maxcuso\Roleplay\Character\State\CharacterSnapshot;
use Maxcuso\Roleplay\Rules\ItemAttackRules;

/**
 * Готовит потенциальные атаки для анкеты, не проводит бой и не выполняет SQL.
 * Владения берём только из приобретённого состояния: бонус характеристики их не выдаёт.
 */
final class CharacterAttackBuilder
{
    public function build(CharacterSnapshot $snapshot): array
    {
        $attacks = [];
        $ownedProficiencies = $snapshot->ownedProficiencyIds();
        $hasEquippedMeleeWeapon = false;
        $seenInstances = [];
        foreach ($snapshot->equipment as $item) {
            if (isset($seenInstances[$item['id']])) {
                throw new \DomainException('Экземпляр оружия повторяется в экипировке.');
            }
            $seenInstances[$item['id']] = true;
            // «Без оружия» означает отсутствие оружия ближнего боя в экипировке.
            // Дальнобойное, метательное и магическое оружие без этого признака допускаются.
            // Наличие владения не влияет на условие: даже неосвоенный меч его отключает.
            $hasEquippedMeleeWeapon = $hasEquippedMeleeWeapon || $item['definition']['is_melee_weapon'];
            foreach (ItemAttackRules::modes() as $usage) {
                if (!$item['definition'][$usage['flag']]) {
                    continue;
                }
                $required = $item['definition'][$usage['proficiency']] ?? null;
                if (!is_int($required) || $required < 1) {
                    throw new \DomainException('Для способа атаки предмета не задано владение.');
                }
                if (!in_array($required, $ownedProficiencies, true)) {
                    continue;
                }
                $attacks[] = [
                    // Экземпляр и способ различаются: две копии меча не получают общую область эффекта.
                    'id' => 'item:'.$item['id'].':'.$usage['mode'],
                    'mode' => $usage['mode'],
                    'damage_units' => $item['definition']['damage_units'],
                    'source' => [
                        'kind' => 'item', 'id' => $item['id'], 'definition_id' => $item['item_id'],
                        'label' => $item['name'], 'proficiency_id' => $required,
                    ],
                ];
            }
        }
        if (!$hasEquippedMeleeWeapon) {
            $attacks[] = [
                'id' => 'unarmed', 'mode' => 'melee', 'damage_units' => 0, 'unarmed' => true,
                'source' => ['kind' => 'attack', 'label' => 'Безоружная атака'],
            ];
        }

        // Уже изученное остаётся доступным при выключении справочника или пути.
        // Круг, затраты и перезарядка относятся к изучению/применению, а не к максимуму урона анкеты.
        $seenSpells = [];
        foreach ($snapshot->spells as $spell) {
            if (isset($seenSpells[$spell['id']])) {
                throw new \DomainException('Изученное заклинание повторяется в состоянии.');
            }
            $seenSpells[$spell['id']] = true;
            if ($spell['definition']['damage_units'] === 0) {
                continue;
            }
            $attacks[] = [
                'id' => 'spell:'.$spell['grant_id'], 'mode' => 'magic',
                'damage_units' => $spell['definition']['damage_units'],
                'source' => [
                    'kind' => 'spell', 'id' => $spell['grant_id'], 'definition_id' => $spell['id'],
                    'label' => $spell['name'], 'magic_path_id' => $spell['definition']['magic_path_id'],
                    'level' => $spell['definition']['level'],
                ],
            ];
        }
        return $attacks;
    }
}
