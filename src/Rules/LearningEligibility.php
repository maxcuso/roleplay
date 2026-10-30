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
            return $this->denied('wrong_attribute', 'Этот выбор относится к другой характеристике. Выберите соответствующую характеристику');
        }
        if ($attributeLevel < 1 || $attributeLevel > $this->rules->maximumAttributeLevel()) {
            return $this->denied('invalid_attribute_level', 'Не удалось определить уровень характеристики. Попробуйте обновить страницу');
        }
        if (!$this->rules->hasAttribute($attribute['slug'] ?? '')) {
            return $this->denied('missing_attribute_rules', 'Правила обучения для этой характеристики не настроены. Обратитесь к администратору');
        }
        if (empty($attribute['enabled']) || empty($definition['enabled'])) {
            return $this->denied('definition_disabled', 'Характеристика или выбранная запись отключена. Новое получение недоступно');
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