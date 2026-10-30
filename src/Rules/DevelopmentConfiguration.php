<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Rules;

/** Данные редактора и адаптер к существующим расчётам. Здесь нет SQL и выдач персонажу.
 * Каждое правило имеет постоянный ключ: перенос уровня не создаёт новую награду.
 */
final class DevelopmentConfiguration
{
    public readonly array $values;
    public function __construct(array $input)
    {
        $this->values = (new DevelopmentConfigurationValidator())->normalize($input);
    }

    /** Файлы нужны только как начальные значения и для чистых тестов без форума. */
    public static function defaults(): self
    {
        $learning = require __DIR__.'/../../resources/rules/development.php';
        $stats = require __DIR__.'/../../resources/rules/stats.php';
        $attributes = [];
        foreach ($learning['attributes'] as $slug => $rules) {
            $rows = [];
            foreach (['skill_choices' => 'skill', 'proficiency_choices' => 'proficiency'] as $field => $type) {
                foreach ($rules[$field] as $level) $rows[] = ['id' => "$slug:$type:$level", 'level' => $level, 'type' => $type, 'value' => 1];
            }
            foreach ($rules['automatic_proficiencies'] as $level => $keys) {
                foreach ($keys as $key) $rows[] = ['id' => "$slug:automatic:$key", 'level' => $level, 'type' => 'automatic', 'value' => $key];
            }
            $growth = [];
            foreach ($stats['stats'] as $name => $definition) {
                if ($definition['attribute'] !== $slug) continue;
                if ($definition['type'] === 'linear') {
                    $growth[$name] = ['initial' => $definition['offset'] + $definition['factor'], 'per_level' => $definition['factor']];
                } else {
                    foreach ($definition['steps'] as $level => $value) {
                        if ($value > 0) $rows[] = ['id' => "$slug:$name:$level", 'level' => $level, 'type' => $name, 'value' => $value];
                    }
                }
            }
            if ($slug === $stats['magic_circle']['attribute']) {
                foreach ($stats['magic_circle']['steps'] as $level => $value) {
                    if ($value > 0) $rows[] = ['id' => "$slug:circle:$level", 'level' => $level, 'type' => 'circle', 'value' => $value];
                }
            }
            $attributes[$slug] = ['growth' => $growth, 'rules' => $rows];
        }
        return new self(DevelopmentDescriptions::seed(['maximum_level' => $learning['max_attribute_level'],
            'skill_minimums' => $learning['skill_minimum_attributes'],
            'experience_costs' => array_map(fn ($level, $cost) => ['minimum' => $level, 'cost' => $cost],
                array_keys(require __DIR__.'/../../resources/rules/experience.php'), array_values(require __DIR__.'/../../resources/rules/experience.php')),
            'attributes' => $attributes]));
    }

    public function learning(): DevelopmentRules
    {
        $attributes = [];
        foreach ($this->values['attributes'] as $slug => $attribute) {
            $entry = ['skill_choices' => [], 'proficiency_choices' => [], 'automatic_proficiencies' => [], 'choice_entries' => []];
            foreach ($attribute['rules'] as $rule) {
                if (in_array($rule['type'], ['skill', 'proficiency'], true)) {
                    for ($ordinal = 1; $ordinal <= $rule['value']; $ordinal++) {
                        $entry[$rule['type'].'_choices'][] = $rule['level'];
                        $entry['choice_entries'][] = ['kind' => $rule['type'], 'threshold' => $rule['level'],
                            'rule_key' => $rule['id'], 'rule_ordinal' => $ordinal];
                    }
                } elseif ($rule['type'] === 'automatic') {
                    $entry['automatic_proficiencies'][$rule['level']][] = $rule['value'];
                }
            }
            $attributes[$slug] = $entry;
        }
        return new DevelopmentRules(['max_attribute_level' => $this->values['maximum_level'],
            'skill_minimum_attributes' => $this->values['skill_minimums'], 'attributes' => $attributes]);
    }

    public function statistics(): StatRules
    {
        $stats = require __DIR__.'/../../resources/rules/stats.php';
        foreach ($stats['stats'] as $name => &$definition) {
            $attribute = $this->values['attributes'][$definition['attribute']];
            if ($definition['type'] === 'linear') {
                $growth = $attribute['growth'][$name];
                $definition['factor'] = $growth['per_level'];
                $definition['offset'] = $growth['initial'] - $growth['per_level'];
            } else $definition['steps'] = $this->steps($attribute['rules'], $name);
        }
        unset($definition);
        $stats['magic_circle']['steps'] = $this->steps($this->values['attributes']['magic']['rules'], 'circle');
        return new StatRules($stats, $this->learning());
    }

    public function cost(int $target): int
    {
        if ($target < 2 || $target > $this->values['maximum_level']) throw new \InvalidArgumentException('Уровень вне диапазона приобретения.');
        $cost = 0;
        foreach ($this->values['experience_costs'] as $range) if ($target >= $range['minimum']) $cost = $range['cost'];
        return $cost;
    }

    private function steps(array $rules, string $type): array
    {
        $steps = [1 => 0];
        foreach ($rules as $rule) if ($rule['type'] === $type) $steps[$rule['level']] = $rule['value'];
        ksort($steps);
        return $steps;
    }
}
