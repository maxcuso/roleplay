<?php

// Начальные правила обучения для установки и чистых тестов.
// После установки действующие значения редактируются в админке: Настройки → Развитие.
return [
    'max_attribute_level' => 25,
    'skill_minimum_attributes' => [1 => 1, 2 => 7, 3 => 15],
    'attributes' => [
        'physiology' => [
            'skill_choices' => [4, 8, 13, 18, 24],
            'proficiency_choices' => [3, 11, 19],
            'automatic_proficiencies' => [],
        ],
        'agility' => [
            'skill_choices' => [5, 7, 9, 11, 15, 18, 21, 24],
            'proficiency_choices' => [2, 14],
            'automatic_proficiencies' => [],
        ],
        'magic' => [
            'skill_choices' => [4, 7, 10, 15, 19, 24],
            'proficiency_choices' => [],
            'automatic_proficiencies' => [5 => ['magic-weapon']],
        ],
        'charisma' => [
            'skill_choices' => [1, 4, 7, 10, 13, 15, 19, 24],
            'proficiency_choices' => [],
            'automatic_proficiencies' => [],
        ],
    ],
];
