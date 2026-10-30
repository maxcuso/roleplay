<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Rules;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Catalog\Shared\AbilityValues;

/** Проверяем форму, границы и неоднозначные пороги; не придумываем баланс за администратора. */
final class DevelopmentConfigurationValidator
{
    public const TYPES = [
        'physiology' => ['skill', 'proficiency', 'automatic', 'melee_damage', 'ignored_damage'],
        'agility' => ['skill', 'proficiency', 'automatic', 'ranged_damage'],
        'magic' => ['skill', 'proficiency', 'automatic', 'magic_damage', 'circle'],
        'charisma' => ['skill', 'proficiency', 'automatic'],
    ];
    public const GROWTH = ['physiology' => ['stamina_max', 'carry_capacity'], 'agility' => [], 'magic' => ['mana_max'], 'charisma' => []];

    public function normalize(array $input): array
    {
        FieldValues::allowed($input, ['maximum_level', 'skill_minimums', 'experience_costs', 'attributes', 'spell_learning_costs']);
        $spellCosts = $input['spell_learning_costs'] ?? [1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 30];
        if (!is_array($spellCosts) || array_keys($spellCosts) !== [1, 2, 3, 4, 5]) $this->invalid('Укажите стоимость изучения для всех пяти кругов');
        foreach ($spellCosts as &$cost) $cost = FieldValues::integer($cost, 'Стоимость изучения', 0, 1000000);
        unset($cost);
        $maximum = FieldValues::integer($input['maximum_level'] ?? null, 'Максимум характеристики', 3, 255);
        $minimums = $input['skill_minimums'] ?? [];
        if (!is_array($minimums) || array_keys($minimums) !== [1, 2, 3]) $this->invalid('Укажите требования для всех трёх уровней навыка');
        $previous = 0;
        foreach ($minimums as $rank => &$value) {
            $value = FieldValues::integer($value, 'Требование навыка', 1, $maximum);
            if ($value <= $previous || ($rank === 1 && $value !== 1)) $this->invalid('Укажите возрастающие требования к уровням навыка. Требование для I уровня должно быть равно 1');
            $previous = $value;
        }
        unset($value);
        $costs = $input['experience_costs'] ?? null;
        if (!is_array($costs) || !array_is_list($costs) || !$costs || count($costs) > 254) $this->invalid('Укажите стоимость повышения уровня');
        foreach ($costs as &$range) {
            if (!is_array($range)) $this->invalid('Проверьте диапазон уровней и стоимость повышения в этой строке');
            FieldValues::allowed($range, ['minimum', 'cost']);
            $range = ['minimum' => FieldValues::integer($range['minimum'] ?? null, 'Начало диапазона', 2, 255),
                'cost' => FieldValues::integer($range['cost'] ?? null, 'Стоимость', 0, 9007199254740991)];
        }
        unset($range);
        usort($costs, fn ($a, $b) => $a['minimum'] <=> $b['minimum']);
        if ($costs[0]['minimum'] !== 2 || count(array_unique(array_column($costs, 'minimum'))) !== count($costs)) $this->invalid('Начните диапазоны стоимости с уровня 2 и уберите повторяющиеся уровни');
        $attributes = $input['attributes'] ?? null;
        if (!is_array($attributes) || count($attributes) !== count(self::TYPES) || array_diff(array_keys(self::TYPES), array_keys($attributes))) $this->invalid('Укажите правила развития для всех четырёх характеристик');
        $seen = [];
        foreach (self::TYPES as $slug => $types) {
            $attribute = $attributes[$slug];
            if (!is_array($attribute)) $this->invalid('Выберите доступную характеристику');
            FieldValues::allowed($attribute, ['growth', 'rules', 'initial_description']);
            $initial_description = $this->description($attribute['initial_description'] ?? null, self::GROWTH[$slug]);
            $growth = $attribute['growth'] ?? [];
            if (!is_array($growth) || count($growth) !== count(self::GROWTH[$slug]) || array_diff(self::GROWTH[$slug], array_keys($growth))) $this->invalid('Проверьте параметры роста ресурсов');
            foreach ($growth as $stat => &$values) {
                if (!is_array($values)) $this->invalid('Выберите доступный параметр ресурса');
                FieldValues::allowed($values, ['initial', 'per_level']);
                foreach (['initial', 'per_level'] as $field) {
                    $values[$field] = $stat === 'carry_capacity'
                        ? AbilityValues::number($values[$field] ?? null, 'Грузоподъёмность', 1000000)
                        : FieldValues::integer($values[$field] ?? null, 'Рост ресурса', 0, 1000000);
                }
            }
            unset($values);
            $rules = $attribute['rules'] ?? null;
            if (!is_array($rules) || !array_is_list($rules) || count($rules) > 1000) $this->invalid('Укажите пороги получения списком');
            $pairs = [];
            foreach ($rules as &$rule) {
                if (!is_array($rule)) $this->invalid('Проверьте параметры выдачи');
                FieldValues::allowed($rule, ['id', 'level', 'type', 'value', 'description']);
                $id = FieldValues::text($rule['id'] ?? null, 'Ключ правила', 64, true);
                if (!preg_match('/^[a-zA-Z0-9:_-]+$/D', $id) || isset($seen[$id])) $this->invalid('Укажите для каждого правила отдельный постоянный системный ключ');
                $seen[$id] = true;
                $level = FieldValues::integer($rule['level'] ?? null, 'Уровень выдачи', 1, $maximum - 1);
                $type = FieldValues::text($rule['type'] ?? null, 'Действие', 32, true);
                if (!in_array($type, $types, true)) $this->invalid('Выберите действие, доступное для этой характеристики');
                $value = $type === 'automatic' ? FieldValues::text($rule['value'] ?? null, 'Владение', 64, true)
                    : FieldValues::integer($rule['value'] ?? null, 'Значение', in_array($type, ['skill', 'proficiency'], true) ? 1 : 0,
                        in_array($type, ['skill', 'proficiency'], true) ? 10 : ($type === 'circle' ? 5 : 1000000));
                $pair = $level.':'.$type.($type === 'automatic' ? ':'.$value : '');
                if (isset($pairs[$pair])) $this->invalid('Такое действие уже задано на этом уровне. Уберите повторную запись или выберите другое действие');
                $pairs[$pair] = true;
                $tokens = match ($type) {
                    'skill' => ['value', 'skill_levels'], 'proficiency' => ['value', 'proficiency_choices'],
                    'automatic' => ['proficiency'], 'circle' => ['value', 'circle'], default => ['value', 'damage'],
                };
                $description = $this->description($rule['description'] ?? null, $tokens);
                $rule = compact('id', 'level', 'type', 'value', 'description');
            }
            unset($rule);
            usort($rules, fn ($a, $b) => $a['level'] <=> $b['level'] ?: strcmp($a['id'], $b['id']));
            $attributes[$slug] = compact('growth', 'rules', 'initial_description');
        }
        return ['maximum_level' => $maximum, 'skill_minimums' => $minimums, 'experience_costs' => $costs, 'attributes' => $attributes, 'spell_learning_costs' => $spellCosts];
    }
    private function description(mixed $input, array $tokens): ?string
    {
        $text = FieldValues::text($input, 'Текст для страницы', 1000);
        preg_match_all('/\{([^{}]*)\}/u', $text ?? '', $matches);
        if (array_diff($matches[1], $tokens) || preg_match('/[{}]/u', preg_replace('/\{[^{}]*\}/u', '', $text ?? ''))) {
            $this->invalid('В тексте есть неизвестная подстановка. Используйте подстановки из списка доступных');
        }
        return $text;
    }
    private function invalid(string $message): never {throw new ApiProblem('invalid_development_configuration', $message);}
}
