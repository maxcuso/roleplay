<?php

// Это начальные данные новой установки. Изменения работающего форума сохраняются в БД.
return [
    'attributes' => [
        'physiology' => ['name' => 'Физиология', 'icon' => 'fas fa-fist-raised', 'sort_order' => 1],
        'agility' => ['name' => 'Ловкость', 'icon' => 'fas fa-user-ninja', 'sort_order' => 2],
        'magic' => ['name' => 'Магия', 'icon' => 'fas fa-magic', 'sort_order' => 3],
        'charisma' => ['name' => 'Харизма', 'icon' => 'fas fa-guitar', 'sort_order' => 4],
    ],
    // Это определения, а не выдачи персонажу. Перки для уровней выбирает администратор.
    'skills' => [
        'unarmed-combat' => ['name' => 'Рукопашный бой', 'attribute' => 'physiology'],
        'awareness' => ['name' => 'Внимательность', 'attribute' => 'physiology'],
        'smithing' => ['name' => 'Кузнечное дело', 'attribute' => 'agility'],
        'sleight-of-hand' => ['name' => 'Ловкость рук', 'attribute' => 'agility'],
        'stealth' => ['name' => 'Скрытность', 'attribute' => 'agility'],
        'acrobatics' => ['name' => 'Акробатика', 'attribute' => 'agility'],
        'alchemy' => ['name' => 'Алхимия', 'attribute' => 'magic'],
        'herbalism' => ['name' => 'Знахарство', 'attribute' => 'magic'],
        'diplomacy' => ['name' => 'Дипломатия', 'attribute' => 'charisma'],
        'deception' => ['name' => 'Обман', 'attribute' => 'charisma'],
        'insight' => ['name' => 'Проницательность', 'attribute' => 'charisma'],
    ],
    'weapon_proficiencies' => [
        'one-handed' => ['name' => 'Одноручное оружие', 'attribute' => 'physiology'],
        'two-handed' => ['name' => 'Двуручное оружие', 'attribute' => 'physiology'],
        'shields' => ['name' => 'Щиты', 'attribute' => 'physiology'],
        'thrown' => ['name' => 'Метательное оружие', 'attribute' => 'agility'],
        'ranged' => ['name' => 'Дальнобойное оружие', 'attribute' => 'agility'],
        'magic-weapon' => ['name' => 'Магическое оружие', 'attribute' => 'magic'],
    ],
];