<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Единый набор порогов для будущих мастера и профиля. Не читает и не изменяет состояние персонажа. */
final class DevelopmentRules
{
    public function __construct(private readonly array $configuration)
    {
        $maximum = $configuration['max_attribute_level'] ?? null;
        if (!is_int($maximum) || $maximum < 3) {
            $this->invalid();
        }
        $minimums = $configuration['skill_minimum_attributes'] ?? null;
        if (!is_array($minimums) || array_keys($minimums) !== [1, 2, 3]) {
            $this->invalid();
        }
        $previous = 0;
        foreach ($minimums as $minimum) {
            if (!is_int($minimum) || $minimum <= $previous || $minimum > $maximum) {
                $this->invalid();
            }
            $previous = $minimum;
        }
        $attributes = $configuration['attributes'] ?? null;
        if (!is_array($attributes) || !$attributes) {
            $this->invalid();
        }
        foreach ($attributes as $slug => $rules) {
            if (!is_string($slug) || !is_array($rules)) {
                $this->invalid();
            }
            foreach (['skill_choices', 'proficiency_choices'] as $kind) {
                $this->validateChoiceLevels($rules[$kind] ?? null);
            }
            $automatic = $rules['automatic_proficiencies'] ?? null;
            if (!is_array($automatic)) {
                $this->invalid();
            }
            foreach ($automatic as $level => $slugs) {
                if (!is_int($level) || $level < 1 || $level >= $maximum || !is_array($slugs)
                    || !array_is_list($slugs) || !$slugs) {
                    $this->invalid();
                }
                foreach ($slugs as $key) {
                    if (!is_string($key) || trim($key) === '') {
                        $this->invalid();
                    }
                }
                if (count(array_unique($slugs)) !== count($slugs)) {
                    $this->invalid();
                }
            }
        }
    }

    /** Загрузка файла отделена от расчётов. В тестах можно явно передать другой набор правил. */
    public static function defaults(): self
    {
        return new self(require __DIR__.'/../../resources/rules/development.php');
    }

    public function maximumAttributeLevel(): int
    {
        return $this->configuration['max_attribute_level'];
    }

    public function maximumSkillLevel(): int
    {
        return count($this->configuration['skill_minimum_attributes']);
    }

    public function minimumSkillAttribute(int $skillLevel): int
    {
        return $this->configuration['skill_minimum_attributes'][$skillLevel];
    }

    public function hasAttribute(string $slug): bool
    {
        return isset($this->configuration['attributes'][$slug]);
    }

    /** Набор характеристик берётся из правил, а не из повторного списка в загрузчике персонажа. */
    public function attributeSlugs(): array
    {
        return array_keys($this->configuration['attributes']);
    }

    public function skillChoiceLevels(string $slug): array
    {
        return $this->attribute($slug)['skill_choices'];
    }

    public function proficiencyChoiceLevels(string $slug): array
    {
        return $this->attribute($slug)['proficiency_choices'];
    }

    /** Число заработанных выборов, не остаток. Расходы учитывает состояние персонажа, не этот расчёт. */
    public function earnedChoices(string $slug, int $attributeLevel): array
    {
        $this->validateAttributeLevel($attributeLevel);
        return [
            'skills' => count(array_filter($this->skillChoiceLevels($slug), fn ($level) => $level <= $attributeLevel)),
            'proficiencies' => count(array_filter($this->proficiencyChoiceLevels($slug), fn ($level) => $level <= $attributeLevel)),
        ];
    }

    public function automaticProficienciesReached(string $slug, int $attributeLevel): array
    {
        $this->validateAttributeLevel($attributeLevel);
        $result = [];
        foreach ($this->attribute($slug)['automatic_proficiencies'] as $level => $slugs) {
            if ($level <= $attributeLevel) {
                $result = array_merge($result, $slugs);
            }
        }
        return array_values(array_unique($result));
    }

    /** Минимальный порог автоматической выдачи; null — владение изучается через выбор. */
    public function automaticProficiencyMinimum(string $attributeSlug, string $proficiencySlug): ?int
    {
        $minimum = null;
        foreach ($this->attribute($attributeSlug)['automatic_proficiencies'] as $level => $slugs) {
            if (in_array($proficiencySlug, $slugs, true)) {
                $minimum = $minimum === null ? $level : min($minimum, $level);
            }
        }
        return $minimum;
    }

    private function attribute(string $slug): array
    {
        if (!$this->hasAttribute($slug)) {
            throw new \InvalidArgumentException('Для характеристики не заданы правила развития.');
        }
        return $this->configuration['attributes'][$slug];
    }

    private function validateAttributeLevel(int $level): void
    {
        if ($level < 1 || $level > $this->maximumAttributeLevel()) {
            throw new \InvalidArgumentException('Уровень характеристики выходит за пределы правил.');
        }
    }

    private function validateChoiceLevels(mixed $levels): void
    {
        if (!is_array($levels) || !array_is_list($levels)) {
            $this->invalid();
        }
        $previous = 0;
        foreach ($levels as $level) {
            // Последний уровень зарезервирован под особую награду.
            if (!is_int($level) || $level <= $previous || $level >= $this->maximumAttributeLevel()) {
                $this->invalid();
            }
            $previous = $level;
        }
    }

    private function invalid(): never
    {
        throw new \InvalidArgumentException('Некорректная конфигурация правил развития.');
    }
}