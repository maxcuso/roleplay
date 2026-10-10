import fixture from '../../preview/fixtures/wizard.json';
import type {CreationPayload, CreationTransport, CreationView, CreationStep, Grant} from '../src/forum/creation/types';

export type Scenario = 'normal' | 'empty' | 'long' | 'limit';
const copy = <T,>(value: T): T => JSON.parse(JSON.stringify(value));

/**
 * Подменяется только транспорт. Форма, очередь сохранения, карточки и стили — боевые.
 * Здесь нет серверных порогов и цен: набор выборов и итоговые числа демонстрационные.
 * Данные хранятся в памяти вкладки; запросы к форуму не выполняются.
 */
export default class DemoTransport implements CreationTransport {
  private view = copy(fixture) as unknown as CreationView;
  delay = 100;

  constructor(scenario: Scenario) {
    const payload = this.view.draft.payload;
    if (scenario === 'empty') {
      Object.assign(payload, {first_name: '', last_name: '', age: null, base_attributes: {},
        racial_attributes: [], skills: [], proficiencies: [], perk_ids: [], spell_ids: [],
        racial_spell_ids: [], loadout: {}});
    }
    if (scenario === 'limit') Object.assign(payload, {perk_ids: [3, 4], spell_ids: [1, 2], racial_spell_ids: [2]});
    if (scenario === 'long') {
      for (const section of ['perks', 'spells'] as const) {
        for (const row of this.view.catalog.definitions[section]) {
          row.description = Array(12).fill(String(row.description)).join('\n\n');
        }
      }
      this.view.catalog.definitions.perks[0].name = 'Очень длинное название перка — родство с древней природой';
    }
    this.refreshExamples();
  }

  async read(): Promise<CreationView> { return copy(this.view); }

  async save(body: {revision: number; step: CreationStep; payload: CreationPayload}): Promise<CreationView> {
    // Настоящий обмен JSON: полезен и для проверки прибавления характеристики.
    await new Promise(resolve => setTimeout(resolve, this.delay));
    this.view.draft = {...this.view.draft, revision: body.revision + 1,
      step: body.step, payload: copy(body.payload)};
    this.refreshExamples();
    return copy(this.view);
  }

  async create(): Promise<{character_id: number}> { return {character_id: 1}; }

  private refreshExamples(): void {
    const {catalog, draft, preview} = this.view;
    const d = catalog.definitions, payload = draft.payload;
    const race = d.races.find(row => row.id === payload.race_id)!;
    const rules = race.rules as Record<string, any>;
    const fixed = rules.fixed_perk_ids as number[];
    const grant = (id: number): Grant => ({id, source_id: 1, kind: 'race', rarity: 'common',
      context: {race_id: race.id, race_name: race.name}});
    preview.perks = [...fixed, ...payload.perk_ids].map(grant);
    preview.spell_choices = {ordinary_count: 2, ordinary_ids: [1, 2, 3, 4],
      racial_count: Number(rules.spell_choice_count ?? 0), racial_ids: rules.spell_choice_ids ?? []};
    preview.spells = Array.from(new Set([...payload.spell_ids, ...payload.racial_spell_ids]))
      .map(id => d.spells.find(row => row.id === id)!).filter(Boolean);
    // Предварительно заданные слоты для визуала; их доступность в игре рассчитывает PHP.
    preview.training_slots = d.attributes.map(a => {
      const options = d.skills.filter(row => row.attribute_id === a.id)
        .map(row => ({id: row.id, name: row.name, level: 1}));
      const index = payload.skills.findIndex(id => options.some(option => option.id === id));
      return {attribute_id: a.id, kind: 'skill', threshold: 1, ordinal: 0,
        payload_index: index < 0 ? null : index, selected_id: index < 0 ? null : payload.skills[index], options};
    });

    const snapshot = preview.review!.snapshot as any;
    snapshot.character = {first_name: payload.first_name, last_name: payload.last_name,
      age: payload.age, race, status: 'creating', rank: null};
    snapshot.attributes = preview.review!.calculation.effective_attributes;
    snapshot.skills = payload.skills.map(id => ({id, name: d.skills.find(row => row.id === id)?.name,
      level: 1, definition: d.skills.find(row => row.id === id)}));
    snapshot.proficiencies = [...payload.proficiencies, ...payload.racial_proficiency_ids].map(id => ({
      id, name: d.proficiencies.find(row => row.id === id)?.name, definition: d.proficiencies.find(row => row.id === id),
    }));
    snapshot.perks = preview.perks.map((g, index) => ({id: g.id, grant_id: index + 1,
      name: d.perks.find(row => row.id === g.id)?.name, definition: d.perks.find(row => row.id === g.id),
      rarity: g.rarity, source: {id: 1, definition: d.sources[0], context: g.context}}));
    snapshot.spells = preview.spells.map((spell, index) => ({id: spell.id, grant_id: index + 1,
      name: spell.name, definition: spell, path: d.paths.find(row => row.id === spell.magic_path_id)}));
    const selectedItems = catalog.configuration.loadout.flatMap(group =>
      group.options.filter(option => (payload.loadout[group.key] ?? []).includes(option.key)).flatMap(option => option.items));
    snapshot.inventory = selectedItems.map((entry, index) => {
      const item = d.items.find(row => row.id === entry.item_id)!;
      return {id: index + 1, item_id: item.id, name: item.name, quantity: entry.quantity, equipped: false,
        definition: item, type: d['item-types'].find(row => row.id === item.item_type_id)};
    });
  }
}