<?php

// Начальные формулы и пороги. После установки их значения берутся из Настройки → Развитие.
// Здесь также остаётся описание механики параметров; названия и иконки — в справочниках.
// type: linear = offset + factor × уровень; thresholds = последний достигнутый порог.
// kind: scalar = общий параметр; attack = вклад в отдельную атаку.
return [
    'stats' => [
        'mana_max' => ['attribute' => 'magic', 'type' => 'linear', 'offset' => 8, 'factor' => 2, 'kind' => 'scalar'],
        'stamina_max' => ['attribute' => 'physiology', 'type' => 'linear', 'offset' => 8, 'factor' => 2, 'kind' => 'scalar'],
        'carry_capacity' => ['attribute' => 'physiology', 'type' => 'linear', 'offset' => 0, 'factor' => 5,
            'kind' => 'scalar', 'fractional' => true],
        'melee_damage' => ['attribute' => 'physiology', 'type' => 'thresholds', 'kind' => 'attack',
            'steps' => [1 => 1, 2 => 3, 9 => 9, 16 => 27]],
        'ranged_damage' => ['attribute' => 'agility', 'type' => 'thresholds', 'kind' => 'attack',
            'steps' => [1 => 1, 3 => 3, 10 => 9, 17 => 27]],
        'magic_damage' => ['attribute' => 'magic', 'type' => 'thresholds', 'kind' => 'attack',
            'steps' => [1 => 1, 5 => 3, 14 => 9]],
        'ignored_damage' => ['attribute' => 'physiology', 'type' => 'thresholds', 'kind' => 'scalar',
            'steps' => [1 => 0, 5 => 1, 10 => 3, 17 => 9, 22 => 18]],
    ],
    // Дальняя атака метательным оружием и его применение в ближнем бою используют Ловкость.
    'attack_modes' => [
        'melee' => 'melee_damage',
        'ranged' => 'ranged_damage',

        'magic' => 'magic_damage',
    ],
    'magic_circle' => ['attribute' => 'magic', 'steps' => [1 => 0, 3 => 1, 7 => 2, 12 => 3, 16 => 4, 18 => 5]],
];
