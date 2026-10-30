<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Episodes;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Character\Calculation\CharacterEffectResolver;
use Maxcuso\Roleplay\Character\State\CharacterSnapshotBuilder;
use Maxcuso\Roleplay\Character\State\CharacterStateReader;
use Maxcuso\Roleplay\Rules\DevelopmentConfigurationStore;

/** Реальные перки и экипировка на момент начисления, без разбора описаний. */
final class RewardExperience
{
    public function __construct(private ConnectionInterface $db) {}

    public function bonuses(int $id): array
    {
        $rules = (new DevelopmentConfigurationStore($this->db))->read();
        $snapshot = (new CharacterSnapshotBuilder(new CharacterStateReader($this->db), $rules->learning()))->build($id);
        $result = [];
        foreach ((new CharacterEffectResolver())->resolve($snapshot) as $effect) {
            if ($effect['mechanic'] === 'quest_experience' && $effect['condition'] === 'always' && $effect['scope'] === 'all') {
                $result[] = ['name' => $effect['source']['label'], 'percent' => $effect['value'], 'source' => $effect['source']];
            }
        }
        return $result;
    }

    public static function calculate(int $base, array $bonuses): int
    {
        // Проценты имеют точность до 0,001. Считаем целыми частями,
        // чтобы float не превратил точный результат в число на единицу меньше.
        $factor = 100000;
        foreach ($bonuses as $bonus) $factor += (int) round($bonus['percent'] * 1000);
        $maximum = 9007199254740991;
        if ($base < 0 || $base > $maximum || $factor < 100000 || $factor > intdiv(PHP_INT_MAX, 100000)) {
            throw new ApiProblem('experience_overflow', 'Уменьшите количество опыта или проверьте бонусы персонажа');
        }
        $whole = intdiv($base, 100000);
        if ($whole > intdiv($maximum, $factor)) throw new ApiProblem('experience_overflow', 'Итоговый опыт превышает допустимое значение');
        $result = $whole * $factor + intdiv(($base % 100000) * $factor, 100000);
        if ($result > $maximum) throw new ApiProblem('experience_overflow', 'Итоговый опыт превышает допустимое значение');
        return $result;
    }

    /** Краткая подсказка по сохранённым бонусам конкретной выдачи, без пересчёта награды. */
    public static function tooltip(int $base, array $bonuses): string
    {
        $percent = 0;
        foreach ($bonuses as $bonus) $percent += (int) round($bonus['percent'] * 1000);
        $formatted = rtrim(rtrim(number_format($percent / 1000, 3, ',', ''), '0'), ',');
        return $base.' опыта'.($percent > 0 ? ' + '.$formatted.'%' : '');
    }
}
