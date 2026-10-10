<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Общие проверки определения. Здесь нет SQL, пользователя, списаний или выдач. */
abstract class LearningEligibility
{
    public function __construct(protected readonly DevelopmentRules $rules) {}

    protected function definitionProblem(array $definition, array $attribute, int $attributeLevel): ?array
    {
        $attributeId = (int) ($attribute['id'] ?? 0);
        if ($attributeId < 1 || (int) ($definition['attribute_id'] ?? 0) !== $attributeId) {
            return $this->denied('wrong_attribute', 'Выбор относится к другой характеристике.');
        }
        if ($attributeLevel < 1 || $attributeLevel > $this->rules->maximumAttributeLevel()) {
            return $this->denied('invalid_attribute_level', 'Некорректный уровень характеристики.');
        }
        if (!$this->rules->hasAttribute($attribute['slug'] ?? '')) {
            return $this->denied('missing_attribute_rules', 'Для характеристики не заданы правила обучения.');
        }
        if (empty($attribute['enabled']) || empty($definition['enabled'])) {
            return $this->denied('definition_disabled', 'Характеристика или выбранная запись выключена.');
        }
        return null;
    }

    protected function denied(string $code, string $reason, array $details = []): array
    {
        return ['allowed' => false, 'code' => $code, 'reason' => $reason] + $details;
    }

    protected function allowed(array $details): array
    {
        return ['allowed' => true, 'code' => 'allowed', 'reason' => null] + $details;
    }
}