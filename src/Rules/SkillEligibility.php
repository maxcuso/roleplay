<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Расчёт получает остаток выборов именно этой характеристики из доверенного состояния персонажа. */
final class SkillEligibility extends LearningEligibility
{
    /**
     * currentLevel = 0 означает ещё не изученный навык.
     * perks — список определений; ownedPerkIds — уже полученные перки независимо от источника.
     * Результат описывает будущий переход. Сохранение выполняет отдельная операция Progression.
     */
    public function evaluate(array $skill, array $attribute, int $attributeLevel, int $currentLevel,
        int $remainingChoices, array $perks, array $ownedPerkIds = []): array
    {
        $problem = $this->definitionProblem($skill, $attribute, $attributeLevel);
        if ($problem !== null) {
            return $problem;
        }
        if ($currentLevel < 0 || $currentLevel > $this->rules->maximumSkillLevel() || $remainingChoices < 0) {
            return $this->denied('invalid_learning_state', 'Не удалось определить состояние обучения. Попробуйте обновить страницу');
        }
        if ($currentLevel === $this->rules->maximumSkillLevel()) {
            return $this->denied('maximum_skill_level', 'Навык уже достиг максимального III уровня');
        }

        $target = $currentLevel + 1;
        $firstChoice = $this->rules->skillChoiceLevels($attribute['slug'])[0] ?? PHP_INT_MAX;
        // Заработанный выбор остаётся пригоден после переноса или удаления его порога.
        $minimum = max($remainingChoices > 0 ? 1 : $firstChoice, $this->rules->minimumSkillAttribute($target));
        if ($attributeLevel < $minimum) {
            return $this->denied('attribute_level_required', 'Для получения этого уровня навыка повысьте характеристику до уровня '.$minimum,
                ['target_level' => $target, 'minimum_attribute_level' => $minimum]);
        }
        if ($remainingChoices === 0) {
            return $this->denied('no_skill_choices', 'У вас нет неиспользованных выборов обучения навыку');
        }

        $perkId = null;
        foreach ($skill['levels'] ?? [] as $level) {
            if ($level['level'] === $target) {
                $perkId = $level['perk_id'];
            }
        }
        $perk = null;
        foreach ($perks as $candidate) {
            if ($candidate['id'] === $perkId) {
                $perk = $candidate;
            }
        }
        if ($perk === null) {
            return $this->denied('missing_level_perk', 'Перк следующего уровня навыка не настроен или удалён. Обратитесь к администратору');
        }
        $owned = in_array($perkId, $ownedPerkIds, true);
        if (empty($perk['enabled']) && !$owned) {
            return $this->denied('perk_disabled', 'Перк следующего уровня отключён. Повышение уровня навыка недоступно');
        }

        return $this->allowed([
            'action' => $currentLevel === 0 ? 'learn' : 'upgrade',
            'target_level' => $target,
            'perk_id' => $perkId,
            'already_owned_perk' => $owned,
            'grant_perk' => !$owned,
            'consume_choice' => true,
            // Уровень II даёт +2 всего, а не +1 +2. Проверки не суммируют предыдущие уровни.
            'check_bonus' => $target,
        ]);
    }
}
