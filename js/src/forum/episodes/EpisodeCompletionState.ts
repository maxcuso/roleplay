import CatalogListState from '../../admin/catalog/CatalogListState';
import type {CatalogRow} from '../../common/catalog/types';
import {requestMessage} from '../../common/requestError';
import type {CharacterRewardDraft, CompletionContext, CompletionDataSource, CompletionPayload, RewardKind} from './completionTypes';

/** Локальная подготовка наград. Начисления и публикация сюда не входят. */
export default class EpisodeCompletionState {
  recipients: CharacterRewardDraft[];
  selectedId: number | null;
  picker: {kind: RewardKind | 'characters'; list: CatalogListState; selected: CatalogRow | null; quantity: number} | null = null;
  error = '';
  errorKind: 'input' | 'operation' = 'input';
  inserting = false;
  choosingCharacter = false;
  private disposed = false;
  private choiceSequence = 0;

  constructor(readonly context: CompletionContext, private readonly source: CompletionDataSource,
    private readonly redraw: () => void = () => m.redraw()) {
    const seen = new Set<number>();
    this.recipients = context.characters.filter(character => {
      if (seen.has(character.id)) return false;
      seen.add(character.id); return true;
    })
      .map(character => ({character: {...character, bonuses: character.bonuses.map(bonus => ({...bonus})),
        perkIds: [...character.perkIds], spellIds: [...character.spellIds]}, experience: 0, gold: 0, items: [], perks: [], spells: []}));
    this.selectedId = this.recipients[0]?.character.id ?? null;
  }

  get selected(): CharacterRewardDraft | undefined {return this.recipients.find(row => row.character.id === this.selectedId);}
  get busy(): boolean {return this.inserting || this.choosingCharacter;}

  select(id: number): void {
    if (this.busy || !this.recipients.some(row => row.character.id === id)) return;
    this.closePicker(); this.selectedId = id; this.error = '';
  }

  remove(id: number): void {
    if (this.busy) return;
    this.closePicker();
    this.recipients = this.recipients.filter(row => row.character.id !== id);
    if (this.selectedId === id) this.selectedId = this.recipients[0]?.character.id ?? null;
    this.error = '';
  }

  openPicker(kind: RewardKind | 'characters'): void {
    if (this.busy || this.disposed || (kind !== 'characters' && !this.selected)) return;
    this.closePicker(); this.error = ''; this.errorKind = 'input';
    const api = kind === 'characters' ? this.source.characters : this.source.catalog(kind);
    const list = new CatalogListState(api, this.redraw);
    if (kind !== 'characters') list.filters.enabled = '1';
    this.picker = {kind, list, selected: null, quantity: 1};
    void list.load();
  }

  closePicker(): void {
    if (this.choosingCharacter) return;
    this.picker?.list.dispose(); this.picker = null; ++this.choiceSequence;
  }

  search(value: string): void {
    if (!this.picker || this.busy) return;
    this.picker.selected = null; this.error = ''; this.picker.list.setSearch(value);
  }

  async page(delta: -1 | 1): Promise<void> {
    const picker = this.picker;
    if (!picker || this.busy || picker.list.loading) return;
    const next = picker.list.page + delta;
    if (next < 1 || (delta > 0 && picker.list.page * picker.list.data.per_page >= picker.list.data.total)) return;
    picker.selected = null; picker.list.page = next; await picker.list.load();
  }

  choose(row: CatalogRow): void {
    if (!this.picker || this.busy || this.picker.list.loading || this.picker.list.error || row.enabled === false) return;
    if (!this.picker.list.data.rows.some(option => option.id === row.id)) return;
    this.picker.selected = row; this.error = '';
  }

  /** Мёртвый/архивный персонаж из устаревших результатов повторно читается до добавления. */
  async addChoice(): Promise<void> {
    const picker = this.picker, row = picker?.selected;
    if (!picker || !row || this.busy || this.disposed || picker.list.loading || picker.list.error) return;
    if (picker.kind === 'characters') {
      const ticket = ++this.choiceSequence;
      this.choosingCharacter = true; this.error = ''; this.errorKind = 'input'; this.redraw();
      try {
        const character = await this.source.character(row.id);
        if (this.disposed || ticket !== this.choiceSequence) return;
        if (!character.available || character.id !== row.id) {
          this.error = 'Выберите доступного активного персонажа'; return;
        }
        if (!this.recipients.some(recipient => recipient.character.id === character.id)) {
          this.recipients.push({character, experience: 0, gold: 0, items: [], perks: [], spells: []});
        }
        this.selectedId = character.id;
      } catch (error) {
        if (!this.disposed && ticket === this.choiceSequence) {
          this.errorKind = 'operation'; this.error = requestMessage(error, 'Не удалось загрузить персонажа. Попробуйте ещё раз');
        }
      } finally {
        if (!this.disposed && ticket === this.choiceSequence) {
          this.choosingCharacter = false;
          if (!this.error) this.closePicker();
          this.redraw();
        }
      }
      return;
    }
    const recipient = this.selected;
    if (!recipient) return;
    const existing = recipient[picker.kind].find(reward => reward.row.id === row.id);
    if (picker.kind === 'items') {
      const quantity = picker.quantity + (existing?.quantity ?? 0);
      if (!Number.isInteger(picker.quantity) || picker.quantity < 1 || quantity > 1000) {
        this.error = 'Укажите количество предметов от 1 до 1000'; return;
      }
      if (existing) existing.quantity = quantity;
      else recipient.items.push({row: {...row}, quantity});
    } else if (!existing) recipient[picker.kind].push({row: {...row}, quantity: 1});
    this.closePicker(); this.error = '';
  }

  removeReward(kind: RewardKind, id: number): void {
    if (this.busy || !this.selected) return;
    this.selected[kind] = this.selected[kind].filter(reward => reward.row.id !== id); this.error = '';
  }

  duplicate(recipient: CharacterRewardDraft, kind: RewardKind, id: number): boolean {
    return kind === 'perks' ? recipient.character.perkIds.includes(id)
      : kind === 'spells' ? recipient.character.spellIds.includes(id) : false;
  }

  totalExperience(recipient: CharacterRewardDraft): number | null {
    const percent = recipient.character.bonuses.reduce((sum, bonus) => sum + bonus.percent, 0);
    const raw = recipient.experience * (100 + percent);
    const result = Math.floor(raw / 100);
    return Number.isSafeInteger(recipient.experience) && recipient.experience >= 0 && Number.isFinite(percent)
      && percent >= -100 && Number.isFinite(raw) && Number.isSafeInteger(result) && result >= 0 ? result : null;
  }

  validate(): string {
    if (this.picker?.selected) return 'Нажмите «Добавить» или закройте выбор';
    if (!this.recipients.length) return 'Добавьте хотя бы одного получателя награды';
    for (const row of this.recipients) {
      if (!row.character.available) return 'Исключите недоступного персонажа из получателей или выберите другого';
      if (this.totalExperience(row) === null) return 'Укажите допустимое целое количество опыта';
      if (!Number.isSafeInteger(row.gold) || row.gold < 0) return 'Укажите целое количество золота не меньше нуля';
      if (row.items.some(item => !Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 1000)) {
        return 'Укажите количество предметов от 1 до 1000';
      }
    }
    return '';
  }

  /** Передаём только базовый опыт и ID. Клиент не фиксирует бонусы или право выдачи.
   * Известные перки/заклинания остаются в намерении: сервер пропустит дубликаты
   * по фактическому состоянию на публикацию, которое может измениться после вставки.
   */
  payload(): CompletionPayload {
    const error = this.validate();
    if (error) throw new Error(error);
    return {event_id: this.context.eventId, discussion_id: this.context.discussionId, revision: this.context.revision,
      recipients: this.recipients.map(row => ({character_id: row.character.id, experience: row.experience, gold: row.gold,
        items: row.items.map(item => ({id: item.row.id, quantity: item.quantity})),
        perks: row.perks.map(perk => perk.row.id), spells: row.spells.map(spell => spell.row.id)}))};
  }

  async insert(callback: (payload: CompletionPayload) => void | Promise<void>): Promise<boolean> {
    if (this.busy || this.disposed) return false;
    this.errorKind = 'input'; this.error = this.validate();
    if (this.error) return false;
    this.inserting = true; this.redraw();
    try {await callback(this.payload()); return !this.disposed;}
    catch (error) {
      if (!this.disposed) {this.errorKind = 'operation'; this.error = requestMessage(error, 'Не удалось вставить код. Попробуйте ещё раз');}
      return false;
    } finally {if (!this.disposed) {this.inserting = false; this.redraw();}}
  }

  dispose(): void {
    this.disposed = true; ++this.choiceSequence;
    this.picker?.list.dispose(); this.picker = null;
  }
}
