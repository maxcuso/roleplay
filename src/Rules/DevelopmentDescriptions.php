<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Rules;

/** Начальные формулировки страницы. После установки редактируются в базе,
 * числа остаются подстановками, а не копиями игровых настроек.
 */
final class DevelopmentDescriptions
{
    public static function rule(string $slug, array $rule): string
    {
        return match ($rule['type']) {
            'skill' => 'Получите {skill_levels} в новом или уже изученном навыке на выбор',
            'proficiency' => 'Получите {proficiency_choices}',
            'automatic' => $rule['value'] === 'magic-weapon' ? 'Вы владеете магическим оружием' : 'Вы получаете владение: {proficiency}',
            'melee_damage' => 'Базовый урон в ближнем бою — {damage}',
            'ranged_damage' => $rule['level'] === 1
                ? 'Базовый урон дальнобойной атаки или атаки метательным оружием в ближнем бою — {damage}'
                : 'Базовый урон — {damage}',
            'magic_damage' => $rule['level'] === 1 ? 'Базовый вклад в магическую атаку — {damage}' : 'Базовый магический урон — {damage}',
            'ignored_damage' => 'Игнорируемый урон — {damage}',
            'circle' => 'Доступ к заклинаниям {circle} круга',
        };
    }

    public static function initial(string $slug): ?string
    {
        return match ($slug) {
            'physiology' => 'Базовый запас выносливости — {stamina_max} ед.',
            'magic' => 'Базовый объём маны — {mana_max} ед.',
            default => null,
        };
    }

    /** Заполняем только отсутствующие тексты, не меняя баланс и постоянные ключи. */
    public static function seed(array $values): array
    {
        foreach ($values['attributes'] as $slug => &$attribute) {
            $attribute['initial_description'] ??= self::initial($slug);
            foreach ($attribute['rules'] as &$rule) $rule['description'] ??= self::rule($slug, $rule);
            unset($rule);
        }
        unset($attribute);
        return $values;
    }
}
