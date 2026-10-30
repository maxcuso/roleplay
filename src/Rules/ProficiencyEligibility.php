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
            return $this->denied('proficiency_already_learned', 'Это владение уже изучено');
        }
        if ($remainingChoices < 0) {
            return $this->denied('invalid_learning_state', 'Не удалось определить состояние обучения. Попробуйте обновить страницу');
        }

        $slug = $attribute['slug'];
        $automaticMinimum = $this->rules->automaticProficiencyMinimum($slug, $proficiency['slug'] ?? '');
        if ($automaticMinimum !== null) {
            if ($attributeLevel < $automaticMinimum) {
                return $this->denied('attribute_level_required', 'Владение будет получено автоматически при уровне характеристики '.$automaticMinimum,
                    ['minimum_attribute_level' => $automaticMinimum]);
            }
            // Магическое оружие на Магии 5 выдаётся автоматически и не требует выбора.
            return $this->allowed(['action' => 'automatic', 'consume_choice' => false]);
        }
        $levels = $this->rules->proficiencyChoiceLevels($slug);
        if (!$levels && $remainingChoices === 0) {
            return $this->denied('no_proficiency_choices', 'Эта характеристика не даёт выбора указанного владения');
        }
        if ($remainingChoices === 0 && $attributeLevel < ($levels[0] ?? PHP_INT_MAX)) {
            return $this->denied('attribute_level_required', 'Для получения владения повысьте характеристику до уровня '.$levels[0],
                ['minimum_attribute_level' => $levels[0]]);
        }
        if ($remainingChoices === 0) {
            return $this->denied('no_proficiency_choices', 'У вас нет неиспользованных выборов владения');
        }
        return $this->allowed(['action' => 'learn', 'consume_choice' => true]);
    }
}
