const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');

(async () => {
  const {modules, m, app, requests, pageClass, expand} = loadClient();
  function exported(name) {
    const value = modules.get(name);
    assert.ok(value, 'Module exists: ' + name);
    return value.default || value;
  }
  // Берём описания из настоящей собранной страницы: Flarum не публикует const-реестры.
  const page = new pageClass();
  page.oninit(m(pageClass, {}));
  const Section = exported('admin/catalog/components/CatalogSection');
  // Иконки характеристик теперь редактируются одной строкой настроек, без отдельного окна записи.
  const definitions = ['races', 'paths', 'item-types', 'sources', 'spells', 'items', 'perks', 'ranks', 'characters', 'skills', 'proficiencies']
    .map(key => {
      page.selected = key;
      return page.content().children.find(node => node.tag === Section).attrs.definition;
    });
  const refs = {
    ranks: [{id: 1, name: 'Алеф', level: 1, letter: 'א', enabled: true}],
    attributes: [
      {id: 1, name: 'Физиология', slug: 'physiology', icon: 'fas fa-fist-raised', enabled: true},
      {id: 2, name: 'Ловкость', slug: 'agility', icon: 'fas fa-user-ninja', enabled: true},
      {id: 3, name: 'Магия', slug: 'magic', icon: 'fas fa-magic', enabled: true},
      {id: 4, name: 'Харизма', slug: 'charisma', icon: 'fas fa-guitar', enabled: true},
    ],
    skills: [{id: 1, name: 'Рукопашный бой', attribute_id: 1, enabled: true, levels: [
      {level: 1, perk_id: 1}, {level: 2, perk_id: 2}, {level: 3, perk_id: 3},
    ]}],
    proficiencies: [
      {id: 1, name: 'Одноручное оружие', slug: 'one-handed', attribute_id: 1, enabled: true},
      {id: 2, name: 'Метательное оружие', slug: 'thrown', attribute_id: 2, enabled: true},
      {id: 3, name: 'Магическое оружие', slug: 'magic-weapon', attribute_id: 3, enabled: true},
    ],
    paths: [{id: 1, name: 'Пламя', color: '#bc3d38', icon: 'fas fa-fire', enabled: true}],
    'item-types': [{id: 1, name: 'Одежда', icon: 'fas fa-tshirt', equipment_limit: 5, enabled: true}],
    sources: [{id: 1, name: 'Раса', icon: 'fas fa-dna', name_source: 'race', attribute_icons: {}, enabled: true}],
    perks: [{id: 1, name: 'Перк', description: 'Текст', image: '/assets/perk.svg', rarity: 'rare', enabled: true},
      {id: 2, name: 'Перк II', enabled: true}, {id: 3, name: 'Перк III', enabled: true}],
    spells: [{id: 1, name: 'Заклинание', magic_path_id: 1, level: 1, damage_units: 14, mana_cost: 2, distance_mode: 'self', cast_mode: 'instant', enabled: true}],
    items: [{id: 1, name: 'Плащ', item_type_id: 1, weight: 1.25, is_magical: true, rarity: 'epic', properties: [{description: 'Текст свойства', cooldown_value: 2, cooldown_unit: 'turn'}], enabled: true}],
    races: [{id: 1, name: 'Человек', quote: '«Мы не дали угаснуть огням»', image: '/role/media/races/Human.png/file',
      rules: {fixed_bonuses: {magic: 1}, fixed_perk_ids: [1], choice_attributes: ['agility']}, enabled: true}],
  };
  const result = {rows: [], total: 0, page: 1, per_page: 30, revision: 7, lookups: refs};
  app.result = result;
  const Editor = exported('common/catalog/CatalogEditorState');
  const Modal = exported('common/catalog/components/CatalogEditModal');
  const Table = exported('admin/catalog/components/CatalogTable');

  const api = {save: async () => {}, list: async () => result};
  for (const definition of definitions) {
    const row = definition.key === 'characters' ? {id: 1, name: 'Имя Фамилия', first_name: 'Имя', revision: 3, status: 'active'} :
      refs[definition.key][0];
    for (const selected of definition.create ? [null, row] : [row]) {
      const modal = new Modal();
      modal.oninit(m(Modal, {definition, row: selected, data: result, api, onSaved: async () => {}}));
      const nodes = expand(modal.view());
      assert.ok(nodes.some(node => node.tag === 'form' && node.attrs.onsubmit), definition.key + ' has submit form');
      assert.ok(nodes.some(node => node.tag === 'button' && node.attrs.type === 'submit'), definition.key + ' has save button');
      assert.notEqual(modal.editor.values, selected, 'Editor owns a separate draft');
      if (definition.key === 'spells') {
        const label = nodes.find(node => node.tag === 'label' && JSON.stringify(node).includes('Стартовое'));
        const checkbox = label && expand(label).find(node => node.tag === 'input' && node.attrs.type === 'checkbox');
        assert.ok(checkbox, 'Spell editor exposes the starting switch');
        assert.equal(checkbox.attrs.checked, true, 'Starting flag defaults to the existing availability');
        checkbox.attrs.onchange({target: {checked: false}});
        assert.equal(modal.editor.values.is_starting, false, 'Starting switch updates the saved draft');
      }
      if (definition.key === 'items') {
        assert.equal(modal.modalHeading().label, selected ? 'Редактирование предмета' : 'Создание предмета');
      }
      if (definition.key === 'races') {
        const ImageUpload = exported('common/catalog/components/ImageUpload');
        assert.equal(nodes.filter(node => node.tag === ImageUpload).length, 1, 'Race editor reuses one shared image uploader');
        assert.ok(nodes.some(node => node.tag === 'input' && node.attrs['aria-label'] === 'Файл изображения расы'));
        if (selected) {
          const quote = nodes.find(node => node.tag === 'textarea' && node.attrs.value === selected.quote);
          assert.ok(quote, 'Race quote is separate from the description');
          quote.attrs.oninput({target: {value: '«Новая цитата»'}});
          assert.equal(modal.editor.values.quote, '«Новая цитата»');
          assert.equal(modal.editor.values.image, selected.image);
        }
      }
    }
    const section = new Section();
    section.oninit(m(Section, {definition}));
    await Promise.resolve();
    section.list.data = {...result, rows: [row], total: 1};
    section.list.loading = false;
    const sectionNodes = expand(section.view());
    if (definition.key === 'characters') {
      const removed = [];
      section.list.api.remove = async (id, revision) => {removed.push({id, revision});};
      const deleteButton = sectionNodes.find(node => node.tag === 'button'
        && String(node.attrs?.['aria-label']).startsWith('Удалить «'));
      assert.ok(deleteButton, 'Character table has deletion button');
      deleteButton.attrs.onclick();
      await new Promise(resolve => setImmediate(resolve));
      assert.deepEqual(removed, [{id: row.id, revision: 3}], 'Deletion sends character version, not catalog version');
      assert.ok(definition.removeConfirmation(row).includes('литературной анкеты останутся'));
    }
    const nodes = expand(m(Table, {definition, rows: [row], lookups: refs, disabled: false, edit: () => {}, remove: () => {}}));
    assert.ok(nodes.some(node => node.tag === 'table'), definition.key + ' renders table');
    section.edit(row);
    assert.equal(app.lastModal.ModalClass, Modal, definition.key + ' edit opens correct modal');
    section.onremove();
    console.log('Table, create/edit form and modal: ' + definition.key + ' OK');
  }
  // Шесть независимых назначений и отдельный признак магических свойств.

  const DamageEditor = exported('common/catalog/components/DamageEditor');
  const itemDefinition = definitions.find(value => value.key === 'items');
  const itemModal = new Modal();
  itemModal.oninit(m(Modal, {definition: itemDefinition, row: refs.items[0], data: result, api}));
  const usageChecks = expand(itemModal.view()).filter(node => node.tag === 'input' && node.attrs?.type === 'checkbox');
  assert.equal(usageChecks.length, 7, 'Any item has six usage flags and a separate magical flag');
  assert.equal(expand(itemModal.view()).filter(node => node.tag === DamageEditor).length, 1, 'No armor editor before armor is selected');
  // Соседние изменения до redraw сохраняются независимо.
  usageChecks[0].attrs.onchange({target: {checked: true}});
  usageChecks[1].attrs.onchange({target: {checked: true}});
  usageChecks[2].attrs.onchange({target: {checked: true}});
  usageChecks[3].attrs.onchange({target: {checked: true}});
  assert.equal(itemModal.editor.values.is_melee_weapon, true);
  assert.equal(itemModal.editor.values.is_ranged_weapon, true);
  assert.equal(itemModal.editor.values.is_magic_weapon, true);
  assert.equal(itemModal.editor.values.is_armor, true);
  usageChecks[4].attrs.onchange({target: {checked: true}});
  usageChecks[5].attrs.onchange({target: {checked: true}});
  assert.equal(itemModal.editor.values.is_ingredient, true);
  assert.equal(itemModal.editor.values.is_literature, true);
  assert.equal(expand(itemModal.view()).filter(node => node.tag === DamageEditor).length, 2, 'Armor selection reveals separate damage reduction editor');
  // Первый обязательный select — тип предмета; остальные — три привязки владений.
  const usageSelectors = expand(itemModal.view()).filter(node => node.tag === 'select' && node.attrs?.required).slice(1);
  assert.equal(usageSelectors.length, 3, 'Each active attack method reveals its own proficiency selector');
  usageSelectors[0].attrs.onchange({target: {value: '1'}});
  usageSelectors[1].attrs.onchange({target: {value: '2'}});
  usageSelectors[2].attrs.onchange({target: {value: '3'}});
  assert.equal(itemModal.editor.values.melee_proficiency_id, 1);
  assert.equal(itemModal.editor.values.ranged_proficiency_id, 2);
  assert.equal(itemModal.editor.values.magic_proficiency_id, 3);
  usageChecks[0].attrs.onchange({target: {checked: false}});
  assert.equal(expand(itemModal.view()).filter(node => node.tag === 'select' && node.attrs?.required).slice(1).length, 2,
    'Disabled usage hides only its selector');
  assert.equal(itemModal.editor.values.melee_proficiency_id, 1, 'Hidden proficiency stays in draft for later reuse');
  usageChecks[0].attrs.onchange({target: {checked: true}});
  itemModal.editor.set('damage_units', 14);
  itemModal.editor.set('ignored_damage_units', 18);

  const armorDamage = new DamageEditor();
  armorDamage.oninit(m(DamageEditor, {state: itemModal.editor, field: 'ignored_damage_units', label: 'Игнорируемый урон'}));
  armorDamage.parts.push({id: armorDamage.nextId++, tier: 3, count: 1});
  armorDamage.sync();
  assert.equal(itemModal.editor.values.ignored_damage_units, 21, 'Armor score uses the same tier input');
  assert.equal(itemModal.editor.values.damage_units, 14, 'Changing defense never changes attack damage');
  usageChecks[3].attrs.onchange({target: {checked: false}});
  assert.equal(expand(itemModal.view()).filter(node => node.tag === DamageEditor).length, 1, 'Unselecting armor hides its editor');
  assert.equal(itemModal.editor.values.ignored_damage_units, 21, 'Hidden armor value is preserved in the draft');
  const itemTypeDefinition = definitions.find(value => value.key === 'item-types');
  // Содержимое литературы: поиск и фильтры не теряют уже выбранные знания.
  {
  const LiteratureEditor = exported('common/editors/LiteratureEditor');
  const MultiPicker = exported('common/catalog/components/MultiPicker');
  const literatureRefs = {...refs, spells: [refs.spells[0],
    {...refs.spells[0], id: 2, name: 'Второе заклинание', level: 2},
    {...refs.spells[0], id: 3, name: 'Иной путь', magic_path_id: 2}],
    paths: [...refs.paths, {id: 2, name: 'Вода', enabled: true}]};
  const literatureState = new Editor(itemDefinition.initial(null), null, 7, api);
  literatureState.set('is_literature', true);
  const literature = new LiteratureEditor();
  literature.oninit(m(LiteratureEditor, {state: literatureState, lookups: literatureRefs}));
  let literatureNodes = expand(literature.view());
  assert.ok(!literatureNodes.some(node => node.tag === MultiPicker), 'Knowledge selector starts collapsed');
  literatureNodes.find(node => node.tag === 'button' && node.attrs['aria-expanded']).attrs.onclick();
  literatureNodes = expand(literature.view());
  let picker = literatureNodes.find(node => node.tag === MultiPicker);
  picker.attrs.onchange([1, 2]);
  assert.deepEqual(literatureState.values.literature_contents.map(row => row.knowledge_id), [1, 2]);
  literature.circle = '2';
  literatureNodes = expand(literature.view());
  picker = literatureNodes.find(node => node.tag === MultiPicker);
  assert.deepEqual(picker.attrs.rows.map(row => row.id), [2], 'Circle filter limits choices');
  assert.deepEqual(Array.from(picker.attrs.selected), [1, 2], 'Filtering keeps contents from other circles');
  assert.ok(literatureNodes.find(node => node.tag === 'input' && node.attrs.type === 'checkbox').attrs.disabled,
    'Multiple knowledge entries cannot become a consumable source');
  literatureNodes.find(node => node.tag === 'button' && node.attrs['aria-label'] === 'Убрать Второе заклинание').attrs.onclick();
  literatureState.set('consumed_on_learning', true);
  literature.circle = '';
  literature.path = '1';
  literatureNodes = expand(literature.view());
  picker = literatureNodes.find(node => node.tag === MultiPicker);
  assert.deepEqual(picker.attrs.rows.map(row => row.id), [1, 2], 'Path filter limits choices');
  const pickerInstance = new MultiPicker();
  pickerInstance.oninit(picker);
  pickerInstance.search = 'Второе';
  const matches = expand(pickerInstance.view()).filter(node => node.tag === 'button');
  assert.equal(matches.length, 1, 'Knowledge search limits visible choices');
  assert.ok(matches[0].attrs.disabled, 'Consumable source blocks a second knowledge entry');
  assert.deepEqual(literatureState.values.literature_contents.map(row => row.knowledge_id), [1]);
  }
  assert.ok(!Object.hasOwn(itemTypeDefinition.initial(null), 'is_weapon'), 'Item types no longer classify weapons');

  // Разметка реальной собранной страницы раскрывается без ошибок keyed fragments.
  page.selected = 'races';
  page.oninit(m(pageClass, {}));
  const pageNodes = expand(page.view());
  const navigation = pageNodes.find(node => node.tag === 'nav');
  for (const label of ['Ранги', 'Навыки и владения', 'Настройки', 'События']) {
    assert.ok(JSON.stringify(navigation).includes(label), 'Main navigation includes ' + label);
  }
  page.selected = 'skills';
  const trainingNodes = expand(page.view());
  const subnav = trainingNodes.find(node => node.tag === 'nav' && node.attrs?.className.includes('RpAdmin-subtabs'));
  assert.equal(subnav.children.length, 2, 'Training group has two subsections');
  subnav.children[1].attrs.onclick();
  assert.equal(page.selected, 'proficiencies', 'Subnavigation selects proficiencies');
  const mainnav = trainingNodes.find(node => node.tag === 'nav');
  mainnav.children[4].attrs.onclick();
  assert.equal(page.selected, 'skills', 'Training group opens skills first');

  // Два изменения из одного дерева до redraw не должны перезаписывать друг друга.
  const skillDefinition = definitions.find(value => value.key === 'skills');
  const skillModal = new Modal();
  skillModal.oninit(m(Modal, {definition: skillDefinition, row: refs.skills[0], data: result, api}));
  const selects = expand(skillModal.view()).filter(node => node.tag === 'select');
  assert.equal(selects.length, 4, 'Skill editor has attribute and three perk selectors');
  selects[1].attrs.onchange({target: {value: '2'}});
  selects[2].attrs.onchange({target: {value: '1'}});
  assert.deepEqual(JSON.parse(JSON.stringify(skillModal.editor.values.levels)), [
    {level: 1, perk_id: 2}, {level: 2, perk_id: 1}, {level: 3, perk_id: 3},
  ], 'Rapid edits preserve both changed links');
  const draft = skillDefinition.initial(null);
  assert.equal(draft.enabled, false, 'New incomplete skill starts disabled');
  assert.ok(!Object.hasOwn(draft, 'icon'), 'Skill has no copied icon');
  const proficiencyDraft = definitions.find(value => value.key === 'proficiencies').initial(null);
  assert.ok(!Object.hasOwn(proficiencyDraft, 'icon') && !Object.hasOwn(proficiencyDraft, 'damage_units')
    && !Object.hasOwn(proficiencyDraft, 'level'), 'Proficiency has neither duplicate icon nor level/damage');
  refs.attributes[0].icon = 'fas fa-star';
  const inherited = expand(m(Table, {definition: skillDefinition, rows: refs.skills, lookups: refs,
    disabled: false, edit: () => {}, remove: () => {}}));
  assert.ok(inherited.some(node => node.tag === 'i' && node.attrs?.className?.includes('fa-star')),
    'Skill table inherits updated attribute icon');
  assert.ok(!definitions.some(value => value.key === 'effects'), 'No standalone effects catalogue');
  const perkDefinition = definitions.find(value => value.key === 'perks');
  const effectModal = new Modal();
  effectModal.oninit(m(Modal, {definition: perkDefinition, row: refs.perks[0], data: result, api}));
  const add = () => expand(effectModal.view()).find(node => node.tag === 'button' && node.attrs?.className === 'Button RpEffects-add').attrs.onclick();
  add(); add();
  let magnitudes = expand(effectModal.view()).filter(node => node.tag === 'input' && String(node.attrs?.['aria-label']).startsWith('Величина эффекта'));
  assert.equal(magnitudes.length, 2);
  magnitudes[0].attrs.oninput({target: {value: '3'}});
  magnitudes[1].attrs.oninput({target: {value: '7'}});
  assert.deepEqual(JSON.parse(JSON.stringify(effectModal.editor.values.effects.map(row => row.value))), [3, 7],
    'Independent values survive two inputs before redraw');
  add();
  const mechanic = expand(effectModal.view()).find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Механика эффекта 3');
  mechanic.attrs.onchange({target: {value: 'text'}});
  assert.equal(effectModal.editor.values.effects[2].value, null);
  assert.equal(effectModal.editor.values.effects[2].stat, null);
  assert.equal(expand(effectModal.view()).filter(node => node.tag === 'input' && String(node.attrs?.['aria-label']).startsWith('Величина эффекта')).length, 2);
  const removeEffect = expand(effectModal.view()).find(node => node.tag === 'button' && node.attrs?.['aria-label'] === 'Убрать эффект 1');
  removeEffect.attrs.onclick();
  assert.equal(effectModal.editor.values.effects.length, 2);
  assert.equal(effectModal.editor.values.effects[0].value, 7, 'Removing first row preserves next value');
  itemModal.editor.set('effects', [{mechanic: 'stat', stat: 'mana_max', operation: 'add', value: 4, condition: 'always', scope: 'all'}]);
  const itemMagicArea = expand(itemModal.view()).find(node => node.tag === 'div' && node.attrs?.className === 'RpItemMagic');
  const magicalCheck = expand(itemMagicArea).find(node => node.tag === 'input' && node.attrs?.type === 'checkbox');
  assert.equal(magicalCheck.attrs.disabled, true, 'Magical flag cannot be removed while effects remain');
  assert.ok(expand(itemModal.view()).some(node => node.tag === 'button' && node.attrs?.className === 'Button RpEffects-add'),
    'Magical item shares the inline editor');
  effectModal.editor.set('effects', [{mechanic: 'stat', stat: 'melee_damage', operation: 'base_percent',
    value: 50, condition: 'unarmed', scope: 'all', stack_group: 'unarmed-base-damage'}]);
  const halfNodes = expand(effectModal.view());
  const groupInput = halfNodes.find(node => node.tag === 'input' && String(node.attrs?.['aria-label']).startsWith('Группа бонусов'));
  assert.equal(groupInput.attrs.value, 'unarmed-base-damage', 'Non-stacking group remains editable');
  const operationInput = halfNodes.find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Операция эффекта 1');
  assert.ok(operationInput.children.some(node => node.attrs?.value === 'base_percent'), 'Attack offers percentage of base');
  halfNodes.find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Параметр эффекта 1')
    .attrs.onchange({target: {value: 'mana_max'}});
  assert.equal(effectModal.editor.values.effects[0].operation, 'add', 'Resource target resets attack-only operation');
  const labels = exported('common/character/effectPresentation');
  assert.equal(labels.effectLabel({value: 50, condition: 'unarmed', scope: 'all', definition: {
    mechanic: 'stat', stat: 'melee_damage', operation: 'base_percent', stack_group: 'unarmed-base-damage'}}),
    '+50% базового урона (без оружия) (не складывается)');
  effectModal.editor.set('effects', []);
  add();
  expand(effectModal.view()).find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Механика эффекта 1')
    .attrs.onchange({target: {value: 'incoming_damage'}});
  assert.equal(effectModal.editor.values.effects[0].condition, 'manual', 'Conditional resistance starts with manual condition');
  effectModal.editor.set('effects', [{mechanic: 'attribute_check', attribute_id: 1, operation: 'add',
    value: 2, condition: 'always', scope: 'all'}]);
  let attributeTarget = expand(effectModal.view()).find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Характеристика эффекта 1');
  assert.ok(attributeTarget.children.some(node => node.attrs?.value === 'all'), 'Checks offer All attributes target');
  attributeTarget.attrs.onchange({target: {value: 'all'}});
  assert.equal(effectModal.editor.values.effects[0].mechanic, 'all_checks');
  assert.equal(effectModal.editor.values.effects[0].attribute_id, null, 'Universal check effect has no catalogue reference');
  const allNodes = expand(effectModal.view());
  assert.equal(allNodes.find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Механика эффекта 1').attrs.value, 'attribute_check');
  attributeTarget = allNodes.find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Характеристика эффекта 1');
  assert.equal(attributeTarget.attrs.value, 'all', 'Universal target stays selected after redraw');
  assert.equal(labels.effectLabel({value: 2, condition: 'always', definition: {mechanic: 'all_checks', operation: 'add'}}),
    '+2 ко всем проверкам характеристик и навыков');
  attributeTarget.attrs.onchange({target: {value: '1'}});
  assert.equal(effectModal.editor.values.effects[0].mechanic, 'attribute_check', 'Specific target restores individual check mechanic');
  assert.equal(effectModal.editor.values.effects[0].attribute_id, 1);
  allNodes.find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Механика эффекта 1')
    .attrs.onchange({target: {value: 'attribute'}});
  assert.ok(!expand(effectModal.view()).find(node => node.tag === 'select' && node.attrs?.['aria-label'] === 'Характеристика эффекта 1')
    .children.some(node => node.attrs?.value === 'all'), 'All target cannot silently increase actual attributes');
  console.log('Effect attachments, operations, groups, conditions and universal checks OK');
  const Template = exported('admin/catalog/components/CatalogTemplateModal');
  expand(m(Template, {rows: refs.perks, select: () => {}}));
  const ui = exported('common/uiIcons');
  assert.equal(ui.catalogueIcon(null), 'fas fa-question-circle');
  const presentation = exported('common/presentation');
  assert.equal(presentation.damage(14), 'Средний + Малый + Незначительный ×2');
  assert.equal(presentation.damage(18), 'Средний ×2');
  const RacePicker = exported('common/catalog/components/MultiPicker');
  const picker = new RacePicker();
  let chosen;
  picker.oninit(m(RacePicker, {rows: refs.perks, selected: [], blocked: [1], onchange: value => {chosen = value;}}));
  assert.equal(expand(picker.view()).find(node => node.tag === 'button').attrs.disabled, true);
  picker.attrs = {...picker.attrs, selected: [1]};
  const selected = expand(picker.view()).find(node => node.tag === 'button');
  assert.equal(selected.attrs.disabled, false);
  selected.attrs.onclick();
  assert.equal(chosen.length, 0, 'Selected perk can be unselected');

  let resolveSave;
  let saves = 0;
  const editor = new Editor({name: 'Черновик'}, 1, 7, {save: () => {saves++; return new Promise(resolve => {resolveSave = resolve;});}});
  const firstSave = editor.save();
  assert.equal(await editor.save(), false);
  assert.equal(saves, 1, 'Duplicate submit is ignored');
  resolveSave();
  assert.equal(await firstSave, true);
  const failed = new Editor({name: 'Не терять'}, 1, 7, {save: async () => {throw {response: {errors: [{detail: 'Конфликт версии'}]}};}});
  assert.equal(await failed.save(), false);
  assert.equal(failed.values.name, 'Не терять');
  assert.equal(failed.error, 'Конфликт версии');
  const List = exported('admin/catalog/CatalogListState');
  const pending = [];
  const list = new List({list: () => new Promise(resolve => pending.push(resolve))}, () => {});
  const older = list.load();
  const newer = list.load();
  pending[1]({...result, total: 20});
  await newer;
  pending[0]({...result, total: 10});
  await older;
  assert.equal(list.data.total, 20, 'Stale response cannot overwrite fresh list');
  const calls = pending.length;
  list.dispose();
  await list.load();
  assert.equal(pending.length, calls, 'Disposed section does not start requests');
  const Api = exported('admin/catalog/CatalogApi');
  const transport = new Api('catalogs/perks');
  await transport.save(null, 7, {name: 'Новый'});
  await transport.save(4, 7, {name: 'Существующий'});
  assert.equal(requests.at(-2).method, 'POST');
  assert.equal(requests.at(-1).method, 'PUT');
  assert.equal(requests.at(-1).url, '/api/roleplay/admin/catalogs/perks/4');
  console.log('Navigation, selection, transport, duplicate save and stale response checks passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
