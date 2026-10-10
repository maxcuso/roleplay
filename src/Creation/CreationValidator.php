<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Rules\DevelopmentRules;

/** Перенос проверки распределения из AttributePreview, без старых таблиц roleplay_rules. */
final class CreationValidator
{
    public function __construct(private readonly DevelopmentRules $rules) {}

    public function attributes(array $payload, array $catalog, array $configuration): array
    {
        $errors = [];
        $rows = [];
        $race = $catalog['races'][$payload['race_id']] ?? null;
        if ($payload['first_name'] === '') {
            $errors[] = 'Укажи имя персонажа.';
        }
        if ($race === null || !$race['enabled']) {
            $errors[] = 'Выбери доступную расу.';
            $race = null;
        }
        if ($payload['age'] === null) {
            $errors[] = 'Укажи возраст.';
        } elseif ($race !== null && (($race['min_age'] !== null && $payload['age'] < $race['min_age'])
            || ($race['max_age'] !== null && $payload['age'] > $race['max_age']))) {
            $errors[] = 'Возраст не подходит выбранной расе.';
        }
        $basicErrors = $errors;
        $errors = [];
        $slugs = $this->rules->attributeSlugs();
        $definitions = array_column($catalog['attributes'], null, 'slug');
        foreach ($slugs as $slug) {
            if (!isset($definitions[$slug]) || !$definitions[$slug]['enabled']) {
                $errors[] = 'Не настроена доступная характеристика: '.$slug.'.';
            }
        }
        $base = $payload['base_attributes'];
        $values = array_values($base);
        // Стартовый набор задаёт бюджет и границы, а не обязательную комбинацию.
        // Например, для [3, 2, 1, 1] допустимы и 3/2/1/1, и 2/2/2/1.
        // Расовые прибавки проверяются ниже и этот бюджет не расходуют.
        $distribution = $configuration['attribute_distribution'];
        $budget = array_sum($distribution);
        $minimum = min($distribution);
        $maximum = max($distribution);
        $outsideRange = array_filter($values, static fn ($value) =>
            !is_int($value) || $value < $minimum || $value > $maximum);
        if (array_diff(array_keys($base), $slugs) || array_diff($slugs, array_keys($base))
            || $outsideRange || array_sum($values) !== $budget) {
            $errors[] = 'Распредели '.$budget.' очков между характеристиками: каждая от '.$minimum.' до '.$maximum.'.';
        }
        $racial = $race['rules'] ?? [];
        $chosen = $payload['racial_attributes'];
        if (count($chosen) !== ($racial['attribute_count'] ?? 0)
            || array_diff($chosen, $racial['choice_attributes'] ?? [])) {
            $errors[] = 'Проверь количество и доступность расовых прибавок.';
        }
        foreach ($slugs as $slug) {
            if (!isset($definitions[$slug]) || !is_int($base[$slug] ?? null)) {
                continue;
            }
            $bonus = $racial['fixed_bonuses'][$slug] ?? 0;
            if (in_array($slug, $chosen, true) && in_array($slug, $racial['choice_attributes'] ?? [], true)) {
                $bonus += $racial['attribute_bonus'];
            }
            $final = $base[$slug] + $bonus;
            if ($final < 1 || $final > $this->rules->maximumAttributeLevel()) {
                $errors[] = 'Расовый бонус выводит характеристику за пределы 1–25.';
                continue;
            }
            $rows[$slug] = [
                'id' => $definitions[$slug]['id'], 'name' => $definitions[$slug]['name'], 'slug' => $slug,
                'enabled' => $definitions[$slug]['enabled'],
                'base_value' => $base[$slug], 'racial_bonus' => $bonus, 'level' => $final,
            ];
        }
        return ['race' => $race, 'attributes' => $rows, 'basic_errors' => $basicErrors,
            'attribute_errors' => $errors, 'errors' => array_merge($basicErrors, $errors),
            'valid' => !$basicErrors && !$errors];
    }
}