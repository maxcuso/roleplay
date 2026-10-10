<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Владение либо изучено, либо нет. Его определение не добавляет урон предмету. */
final class ProficiencyEligibility extends LearningEligibility
{
    public function evaluate(array $proficiency, array $attribute, int $attributeLevel,
        bool $learned, int $remainingChoices): array
    {
        $problem = $this->definitionProblem($proficiency, $attribute, $attributeLevel);
        if ($problem !== null) {
            return $problem;
        }
        if ($learned) {
            return $this->denied('proficiency_already_learned', 'Владение уже изучено.');
        }
        if ($remainingChoices < 0) {
            return $this->denied('invalid_learning_state', 'Некорректное состояние обучения.');
        }

        $slug = $attribute['slug'];
        $automaticMinimum = $this->rules->automaticProficiencyMinimum($slug, $proficiency['slug'] ?? '');
        if ($automaticMinimum !== null) {
            if ($attributeLevel < $automaticMinimum) {
                return $this->denied('attribute_level_required', 'Для автоматического получения нужна характеристика '.$automaticMinimum.'.',
                    ['minimum_attribute_level' => $automaticMinimum]);
            }
            // Магическое оружие на Магии 5 выдаётся автоматически и не требует выбора.
            return $this->allowed(['action' => 'automatic', 'consume_choice' => false]);
        }
        $levels = $this->rules->proficiencyChoiceLevels($slug);
        if (!$levels) {
            return $this->denied('no_proficiency_choices', 'Характеристика не предоставляет выбор этого владения.');
        }
        if ($attributeLevel < $levels[0]) {
            return $this->denied('attribute_level_required', 'Для получения владения нужна характеристика '.$levels[0].'.',
                ['minimum_attribute_level' => $levels[0]]);
        }
        if ($remainingChoices === 0) {
            return $this->denied('no_proficiency_choices', 'Нет неиспользованного выбора владения.');
        }
        return $this->allowed(['action' => 'learn', 'consume_choice' => true]);
    }
}