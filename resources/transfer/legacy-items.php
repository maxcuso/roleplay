<?php
// Только прежние стартовые предметы с известным назначением.
// Карта используется однократно при переносе. Дальше все назначения редактируются у предмета.
return [
    'sword' => ['melee' => 'one-handed'],
    'greatsword' => ['melee' => 'two-handed'],
    'bow' => ['ranged' => 'ranged'],
    'throwing-knife' => ['ranged' => 'thrown'],
    'magic-staff' => ['magic' => 'magic-weapon'],
    // Защиту щита нельзя придумать из имени: отдельное поле остаётся исходным нулём.
    'shield' => ['armor' => true],
];