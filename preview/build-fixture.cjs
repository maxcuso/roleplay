/**
 * Только вымышленные примеры для визуального стенда, без экспорта базы форума.
 * Запускать при изменении примеров: node preview/build-fixture.cjs.
 * Это не игровые настройки и не расчёт правил: итоговые числа — готовый пример.
 */
const fs = require('node:fs');
const path = require('node:path');
const row = (id, name, fields = {}) => ({id, name, enabled: true, ...fields});
const attributes = [
  row(1, 'Физиология', {slug: 'physiology', icon: 'fas fa-fist-raised'}),
  row(2, 'Ловкость', {slug: 'agility', icon: 'fas fa-user-ninja'}),
  row(3, 'Магия', {slug: 'magic', icon: 'fas fa-magic'}),
  row(4, 'Харизма', {slug: 'charisma', icon: 'fas fa-guitar'}),
];
const races = [
  row(1, 'Человек', {min_age: 12, max_age: 90, rules: {
    fixed_bonuses: {}, choice_attributes: attributes.map(a => a.slug),
    attribute_bonus: 1, attribute_count: 3, fixed_perk_ids: [1],
    perk_choice_ids: [3, 4, 5, 6], perk_choice_count: 2, proficiency_choice_count: 0,
  }}),
  row(2, 'Эльф', {min_age: 20, max_age: 300, rules: {
    fixed_bonuses: {agility: 1, magic: 1}, choice_attributes: [], attribute_count: 0,
    fixed_perk_ids: [1, 2], perk_choice_ids: [3, 4, 5, 6], perk_choice_count: 2,
    spell_choice_count: 1, spell_choice_ids: [1, 2], proficiency_choice_count: 0,
  }}),
  row(3, 'Полуэльф', {min_age: 16, max_age: 160, rules: {
    fixed_bonuses: {charisma: 1}, choice_attributes: [], attribute_count: 0,
    fixed_perk_ids: [2], perk_choice_ids: [3, 4, 5, 6], perk_choice_count: 1,
    proficiency_choice_mode: 'all', proficiency_choice_count: 1,
  }}),
];
const skills = [
  ['Рукопашный бой', 1], ['Внимательность', 1], ['Кузнечное дело', 2],
  ['Ловкость рук', 2], ['Скрытность', 2], ['Акробатика', 2],
  ['Алхимия', 3], ['Знахарство', 3], ['Дипломатия', 4], ['Обман', 4], ['Проницательность', 4],
].map(([name, attribute_id], i) => row(i + 1, name, {attribute_id}));
const proficiencies = [
  ['Одноручное оружие', 'one-handed', 1], ['Двуручное оружие', 'two-handed', 1],
  ['Щиты', 'shields', 1], ['Метательное оружие', 'throwing', 2],
  ['Дальнобойное оружие', 'ranged', 2], ['Магическое оружие', 'magic-weapon', 3],
].map(([name, slug, attribute_id], i) => row(i + 1, name, {slug, attribute_id}));
const perks = [
  ['Родство с природой', 'Ваше присутствие успокаивает диких животных. Вы можете распознавать простые признаки болезни растений.'],
  ['Наследие фей', 'Вы замечаете следы волшебства и чувствуете настроение окружающего леса.'],
  ['Острый взгляд', 'Вы замечаете небольшие детали, которые другие могли пропустить.'],
  ['Древняя магия', 'Вы знакомы с древними обрядами и узнаёте символы забытых традиций.'],
  ['Упрямство выжившего', 'После тяжёлых испытаний вы находите силы продолжить путь.'],
  ['Хранитель знаний', 'Вы помните истории, обычаи и легенды своего народа.'],
].map(([name, description], i) => row(i + 1, name, {
  description, image: '/assets/nature.svg', rarity: 'uncommon', effects: [],
}));
const paths = [
  row(1, 'Путь Мороза', {icon: 'fas fa-snowflake', color: '#84bdd1'}),
  row(2, 'Путь Пламени', {icon: 'fas fa-fire', color: '#bc3d38'}),
];
const spells = [
  row(1, 'Заморозки', {magic_path_id: 1, description: 'Вы создаёте один из следующих магических эффектов в пределах дистанции:\n• Сформировать в ладони маленький хрупкий ледяной кристалл произвольной формы — он не может являться оружием.'}),
  row(2, 'Ледяной щит', {magic_path_id: 1, description: 'Тонкий слой льда защищает цель. Лёд постепенно тает и исчезает после окончания действия заклинания.'}),
  row(3, 'Огненные ладони', {magic_path_id: 2, description: 'Ваши ладони охватывает мягкое пламя. Вы можете осветить тёмный проход или зажечь небольшой костёр.'}),
  row(4, 'Искра', {magic_path_id: 2, description: 'Создайте короткую вспышку света в выбранной точке.'}),
].map((spell, i) => ({...spell, level: 1, distance_mode: i % 2 ? 'distance' : 'self',
  distance_value: i % 2 ? 10 : null, radius_value: i === 3 ? 2 : 0,
  duration_value: 2, duration_unit: 'turn', cast_mode: 'instant', cast_value: 0,
  cast_unit: 'turn', cooldown_value: 2, cooldown_unit: 'turn',
  mana_cost: i % 2 ? 2 : 0, stamina_cost: 0, other_cost: '', damage_units: i === 3 ? 3 : 0,
}));
const types = [
  row(1, 'Оружие', {icon: 'fas fa-gavel', equipment_limit: 2}),
  row(2, 'Одежда', {icon: 'fas fa-tshirt', equipment_limit: 5}),
];
const items = [
  row(1, 'Плащ тихой поступи', {item_type_id: 2, rarity: 'epic', is_magical: true,
    properties: [{description: 'Ткань поглощает звуки шагов. Пока капюшон поднят, вашу походку сложнее услышать.', cooldown_value: 2, cooldown_unit: 'turn'}]}),
  row(2, 'Меч новичка', {item_type_id: 1, rarity: 'common', damage_units: 3,
    properties: [{description: 'Простой одноручный меч для путешествий и тренировок.', cooldown_value: 0}]}),
  row(3, 'Дорожный лук', {item_type_id: 1, rarity: 'uncommon', damage_units: 3,
    properties: [{description: 'Лёгкий лук из гибкого дерева. В комплекте колчан со стрелами.', cooldown_value: 0}]}),
  row(4, 'Дорожная одежда', {item_type_id: 2, rarity: 'common', properties: []}),
].map(item => ({weight: 1, base_price: 1, damage_units: 0, max_stack: 1,
  appearance: 'Аккуратно изготовленная вещь со следами долгих путешествий.', ...item}));
const sources = [
  row(1, 'Раса', {icon: 'fas fa-dna', name_source: 'race'}),
  row(2, 'Навык', {icon: 'fas fa-graduation-cap', name_source: 'skill', icon_source: 'skill'}),
];
const payload = {
  first_name: 'Лиана', last_name: 'Волкова', age: 24, race_id: 2,
  base_attributes: {physiology: 2, agility: 1, magic: 3, charisma: 1}, racial_attributes: [],
  skills: [2, 7, 10], proficiencies: [1], racial_proficiency_ids: [],
  perk_ids: [3], spell_ids: [1], racial_spell_ids: [], loadout: {weapon: ['sword']},
};
const value = value => ({value, parts: []});
const calculation = {
  // Числа не пересчитываем на стенде: проверка правил остаётся задачей серверных тестов.
  stats: {mana_max: value(16), stamina_max: value(12), carry_capacity: value(16), ignored_damage: value(0)},
  base_damage: {melee_damage: value(1), ranged_damage: value(1), magic_damage: value(3)},
  maximum_damage: {melee_damage: {damage: value(1)}, ranged_damage: null, magic_damage: null},
  magic_circle: 1, inventory_weight: value(2), effective_attributes: {},
  acquired_attributes: {}, effects: [], action_bonuses: [], skill_check_bonuses: {}, check_effects: {},
};
attributes.forEach((a, i) => {
  calculation.effective_attributes[a.slug] = {...a, level: [2, 2, 4, 1][i], base_value: [2, 1, 3, 1][i], racial_bonus: [0, 1, 1, 0][i], sources: []};
});
calculation.acquired_attributes = calculation.effective_attributes;
const view = {
  catalog: {revision: 1, rules_hash: 'visual-preview', definitions: {
    attributes, races, skills, proficiencies, perks, paths, spells, items, 'item-types': types, sources, effects: [],
  }, configuration: {
    enabled: true, attribute_distribution: [3, 2, 1, 1], perk_sources: {race: 1, skill: 2},
    starting_gold: 10, starting_experience: 0,
    loadout: [
      {key: 'weapon', name: 'Оружие', min: 1, max: 1, automatic: false, options: [
        {key: 'sword', name: 'Меч новичка', items: [{item_id: 2, quantity: 1}]},
        {key: 'bow', name: 'Дорожный лук', items: [{item_id: 3, quantity: 1}]},
      ]},
      {key: 'extra', name: 'Дополнительное снаряжение', min: 0, max: 1, automatic: false, options: [
        {key: 'cloak', name: 'Плащ тихой поступи', items: [{item_id: 1, quantity: 1}]},
        {key: 'clothes', name: 'Дорожная одежда', items: [{item_id: 4, quantity: 1}]},
      ]},
    ],
  }},
  draft: {id: 1, revision: 1, catalog_revision: 1, rules_hash: 'visual-preview', step: 'basics', payload},
  existing_character_id: null,
  preview: {valid: true, errors: {}, step_validity: Object.fromEntries(
    ['basics', 'attributes', 'training', 'perks', 'spells', 'loadout', 'review'].map(key => [key, true])),
    attributes: {}, training_slots: [], racial_proficiency_ids: [1, 2, 3, 4, 5, 6],
    perks: [], spells: [], skills: [], proficiencies: [], spell_choices: {},
    review: {snapshot: {character: {}, attributes: {}, skills: [], proficiencies: [], perks: [], spells: [],
      inventory: [], equipment: [], balances: {experience_active: 0, experience_total: 0, gold: 10}, choices: [],
      effect_definitions: {}}, calculation},
  },
};
fs.mkdirSync(path.join(__dirname, 'fixtures'), {recursive: true});
fs.writeFileSync(path.join(__dirname, 'fixtures/wizard.json'), JSON.stringify(view, null, 2) + '\n');
console.log('Вымышленный пример мастера подготовлен.');