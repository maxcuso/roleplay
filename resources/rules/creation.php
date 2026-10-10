<?php

// Начальные скалярные настройки. Конкретные ID источников и стартовых вещей задаются отдельно.
// Не включаем создание до настройки каталогов и источников; миграция не содержит игровых данных.
return [
    'enabled' => false,
    'max_characters' => 1,
    // Сумма — стартовые очки, минимум/максимум — границы до расовых бонусов.
    'attribute_distribution' => [3, 2, 1, 1],
    'starting_spell_count' => 2,
    'starting_gold' => 10,
    'starting_experience' => 0,
    'perk_sources' => [],
    // Наборы: обязательные оружие и дополнительный предмет; сменная одежда автоматически.
    // ID предметов привязываются через настройки после заполнения каталога.
    'loadout' => [],
    'application' => \Maxcuso\Roleplay\Character\Application\ApplicationConfiguration::defaults(),
];