/**
 * Тексты и примеры только для макета /role.
 * Здесь меняются подписи плиток и состав слайдера без разбора кода интерфейса.
 * Реальные анкеты добавим после отбора: demo заменяется ссылкой на тему.
 */
// title — подпись; icon — ключ иконки; tone — оформление и id готовой HTML-секции.
export const guides = [
    {
        title: 'Правила игры',
        icon: 'effects.allChecks',
        tone: 'rules'
    },
    {
        title: 'Мир',
        icon: 'landing.lore',
        tone: 'lore'
    },
    {
        title: 'Персонаж',
        icon: 'extension',
        tone: 'application'
    },
];
// Имена и биографии вымышлены. Они не добавляют NPC и новые правила в мир.
// portrait: положение портрета в общей иллюстрации (0–3).
export const characters = [
    {
        name: 'Эйдан Роу',
        race: 'Человек',
        vocation: 'Следопыт',
        portrait: 0,
        description: 'Знает, куда ведёт любая дорога. Кроме той, что привела его в гильдию.',
        hook: 'Каждая тропа хранит след.',
        demo: true
    },
    {
        name: 'Лиэль',
        race: 'Эльф',
        vocation: 'Исследовательница',
        portrait: 1,
        description: 'Собирает осколки забытых знаний и задаёт вопросы, на которые лучше бы не знать ответов.',
        hook: 'Тайны не раскрываются сами.',
        demo: true
    },
    {
        name: 'Бранн Меднобород',
        race: 'Дварф',
        vocation: 'Мастер',
        portrait: 2,
        description: 'Считает, что хорошая сталь и надёжные спутники решают большинство проблем.',
        hook: 'Сначала дело. Потом легенда.',
        demo: true
    },
    {
        name: 'Мира Вейл',
        race: 'Человек',
        vocation: 'Наёмница',
        portrait: 3,
        description: 'Не обещает лёгкой победы. Обещает, что не оставит своих по ту сторону моста.',
        hook: 'Вернуться должны все.',
        demo: true
    },
];

// Характеристики макета в формате справочника attributes.
// На форуме сюда передаются catalog.definitions.attributes с актуальными иконками из базы.
export const attributes = [
    {
        "slug": "physiology",
        "name": "Физиология",
        "icon": "fas fa-fist-raised"
    },
    {
        "slug": "agility",
        "name": "Ловкость",
        "icon": "fas fa-user-ninja"
    },
    {
        "slug": "magic",
        "name": "Магия",
        "icon": "fas fa-magic"
    },
    {
        "slug": "charisma",
        "name": "Харизма",
        "icon": "fas fa-guitar"
    }
];
