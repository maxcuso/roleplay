import {object, type Fields} from '../../common/catalogData';
import {requestMessage, requestStatus} from '../../common/requestError';
import {messageKind} from '../../common/messageKind';
import type {CharacterSummary, ProfileView, ProfileTransport, ProfileCommand, ReviewPart, ReviewAction, RoleplayerStats} from './types';

/** Состояние страницы без зависимости от Flarum. Поздний ответ не заменяет другого выбранного персонажа. */
export default class ProfileState {
  characters: CharacterSummary[] = [];
  selected: number | null = null;
  view: ProfileView | null = null;
  roleplayer: RoleplayerStats | null = null;
  nextBefore: number | null = null;
  loading = false;
  loadingMore = false;
  detailLoading = false;
  changing = false;
  listError = '';
  detailError = '';
  actionError = '';
  clearInputError(): void {
    if (this.actionError && messageKind(this.actionError) === 'input') this.actionError = '';
  }
  retryAvailable = false;
  private userId: number | null = null;
  private initialCharacter: number | null = null;
  private listGeneration = 0;
  private selectionGeneration = 0;
  private disposed = false;
  private pending: ({characterId: number} & ProfileCommand) | null = null;

  constructor(private readonly api: ProfileTransport, private readonly redraw: () => void,
    private readonly operationKey: () => string = () => crypto.randomUUID()) {}

  async loadUser(userId: number, preferredCharacter: number | null = null): Promise<void> {
    if (this.changing) return;
    this.userId = userId;
    this.initialCharacter = preferredCharacter;
    this.characters = [];
    this.selected = null;
    this.view = null;
    this.roleplayer = null;
    this.nextBefore = null;
    this.selectionGeneration++;
    this.loading = false;
    this.loadingMore = false;
    this.detailLoading = false;
    this.detailError = '';
    this.pending = null;
    this.retryAvailable = false;
    this.actionError = '';
    await this.loadList();
  }

  async loadList(more = false): Promise<void> {
    if (this.userId === null || this.loadingMore || more && this.nextBefore === null) return;
    const generation = ++this.listGeneration;
    const userId = this.userId;
    if (more) this.loadingMore = true; else this.loading = true;
    this.listError = '';
    this.notify();
    try {
      const result = await this.api.list(userId, more ? this.nextBefore! : undefined);
      if (this.disposed || generation !== this.listGeneration) return;
      const existing = new Set(this.characters.map(row => row.id));
      this.characters = more
        ? [...this.characters, ...result.characters.filter(row => !existing.has(row.id))]
        : result.characters;
      this.nextBefore = result.next_before;
      this.roleplayer = result.roleplayer ?? this.roleplayer;
      if (this.selected === null && this.characters.length) {
        // Ссылка из мастера выбирает созданного персонажа только среди доступных записей этого профиля.
        const requested = this.initialCharacter;
        this.initialCharacter = null;
        void this.select(requested ?? (this.characters.find(row => row.status === 'active') ?? this.characters[0]).id);
      }
      else if (this.selected === null && this.initialCharacter !== null) {
        const requested = this.initialCharacter; this.initialCharacter = null; void this.select(requested);
      }
    } catch (error) {
      if (generation === this.listGeneration) this.listError = requestMessage(error, 'Не удалось загрузить персонажей. Попробуйте ещё раз');
    } finally {
      if (generation === this.listGeneration) { this.loading = false; this.loadingMore = false; this.notify(); }
    }
  }

  async select(id: number): Promise<void> {
    if (this.changing) return;
    const generation = ++this.selectionGeneration;
    this.selected = id;
    this.view = null;
    this.detailLoading = true;
    this.detailError = '';
    this.actionError = '';
    this.pending = null;
    this.retryAvailable = false;
    this.notify();
    try {
      const view = await this.api.read(id);
      if (!this.disposed && generation === this.selectionGeneration) {
        const character = object(view.snapshot.character);
        if (this.userId !== null && Number(character.user_id) !== this.userId) {
          this.detailError = 'Персонаж не найден. Попробуйте обновить страницу'; return;
        }
        this.view = view;
        if (!this.characters.some(row => row.id === id)) this.characters.unshift({id,
          name: [character.first_name, character.last_name].filter(Boolean).join(' '), status: String(character.status)});
        this.roleplayer = view.roleplayer ?? this.roleplayer;
      }
    } catch (error) {
      if (generation === this.selectionGeneration) this.detailError = requestMessage(error, 'Не удалось открыть анкету. Попробуйте ещё раз');
    } finally {
      if (generation === this.selectionGeneration) { this.detailLoading = false; this.notify(); }
    }
  }

  async changeEquipment(instanceId: number, equipped: boolean): Promise<boolean> {
    if (this.actionsBlocked || !this.view?.permissions.can_equip || this.selected === null) return false;
    this.pending = {characterId: this.selected, kind: 'equipment', body: {...this.versions(), instance_id: instanceId, equipped}};
    return this.sendCommand();
  }

  /** Создание сущности меняет версию справочников. Обновляем анкету без сброса окна выдачи и его черновика. */
  async refreshAfterCatalogChange(): Promise<void> {
    if (this.selected === null || this.actionsBlocked) return;
    const id = this.selected, generation = this.selectionGeneration;
    const view = await this.api.read(id);
    if (!this.disposed && generation === this.selectionGeneration) {
      this.view = view;
      this.roleplayer = view.roleplayer ?? this.roleplayer;
      this.notify();
    }
  }
  async changeReview(part: ReviewPart, action: ReviewAction, comment = ''): Promise<boolean> {
    const review = this.view?.review;
    if (part === 'literature' && !['submit', 'withdraw'].includes(action)
      || part === 'mechanics' && action === 'withdraw' || this.actionsBlocked || !review || this.selected === null) return false;
    const allowed = action === 'submit'
      ? part === 'mechanics' ? review.permissions.can_submit_mechanics : review.permissions.can_submit_literature
      : action === 'withdraw' ? review.permissions.can_withdraw_literature : review.permissions.can_moderate;
    if (!allowed) return false;
    this.pending = {characterId: this.selected, kind: 'review', body: {
      ...this.versions(), part, action, comment, topic_hash: review.topic_hash,
    }};
    return this.sendCommand();
  }
  async correctIdentity(firstName: string, lastName: string, age: number): Promise<boolean> {
    if (this.actionsBlocked || !this.view?.review?.permissions.can_edit_identity || this.selected === null) return false;
    this.pending = {characterId: this.selected, kind: 'identity', body: {
      ...this.versions(), first_name: firstName, last_name: lastName, age,
    }};
    return this.sendCommand();
  }
  async develop(kind: 'attribute' | 'skill' | 'proficiency' | 'spell', targetId: number, sourceId?: number): Promise<boolean> {
    if (this.actionsBlocked || !this.view?.development || this.selected === null || !this.api.progress) return false;
    this.pending = {characterId: this.selected, kind: 'progression', body: {...this.versions(), kind, target_id: targetId, rules_hash: this.view.development.rules_hash,
      ...(kind === 'spell' ? {source_instance_id: sourceId} : {})}};
    return this.sendCommand();
  }
  async manage(action: string, data: Fields, comment: string): Promise<boolean> {
    if (this.actionsBlocked || !this.view?.permissions.can_manage || this.selected === null || !this.api.manage) return false;
    this.pending = {characterId: this.selected, kind: 'management', body: {...this.versions(), action, data, comment}};
    return this.sendCommand();
  }
  async retryOperation(): Promise<boolean> {
    return !this.changing && this.retryAvailable && this.pending ? this.sendCommand() : false;
  }
  // Прежнее имя оставлено для кнопки повторения экипировки и существующих потребителей.
  async retryEquipment(): Promise<void> { await this.retryOperation(); }

  get actionsBlocked(): boolean { return this.disposed || this.changing || this.pending !== null; }
  private versions(): {operation_key: string; revision: number; catalog_revision: number} {
    return {operation_key: this.operationKey(), revision: Number(object(this.view!.snapshot.character).revision),
      catalog_revision: Number(this.view!.snapshot.catalog_revision)};
  }
  /** Одна очередь для всех команд: блокируем двойной клик и сохраняем тело/UUID для точного повтора. */
  private async sendCommand(): Promise<boolean> {
    const pending = this.pending!;
    const generation = this.selectionGeneration;
    this.changing = true;
    this.actionError = '';
    this.retryAvailable = false;
    this.notify();
    try {
      const view = await (pending.kind === 'equipment' ? this.api.equip(pending.characterId, pending.body)
        : pending.kind === 'review' ? this.api.review(pending.characterId, pending.body)
        : pending.kind === 'identity' ? this.api.correct(pending.characterId, pending.body)
        : pending.kind === 'progression' ? this.api.progress!(pending.characterId, pending.body)
        : this.api.manage!(pending.characterId, pending.body));
      if (!this.disposed && generation === this.selectionGeneration) {
        this.view = view;
        this.roleplayer = view.roleplayer ?? this.roleplayer;
        this.characters = this.characters.map(row => row.id === pending.characterId
          ? {...row, name: [object(view.snapshot.character).first_name, object(view.snapshot.character).last_name].filter(Boolean).join(' '),
            status: String(object(view.snapshot.character).status)} : row);
        this.pending = null;
        return true;
      }
    } catch (error) {
      if (this.disposed || generation !== this.selectionGeneration) return false;
      const status = requestStatus(error);
      // При потере ответа повторяем тот же UUID: выдачи и решения не записываются дважды.
      this.retryAvailable = status === undefined || status === 0 || status >= 500;
      if (!this.retryAvailable) this.pending = null;
      this.actionError = requestMessage(error, this.retryAvailable
        ? 'Не удалось получить ответ сервера. Попробуйте обновить страницу'
        : 'Не удалось выполнить действие. Попробуйте обновить страницу');
      if (status === 409 || status === 403 || status === 404) {
        // После изменения прав/версии старая анкета не даёт права отправить новую команду.
        if (this.view) {
          this.view.permissions.can_equip = false;
          this.view.permissions.can_manage = false;
          this.view.development = null;
          const permissions = this.view.review?.permissions;
          if (permissions) {
            permissions.can_moderate = false;
            permissions.can_edit_identity = false;
            permissions.can_edit_mechanics = false;
            permissions.can_submit_mechanics = false;
            permissions.can_submit_literature = false;
            permissions.can_withdraw_literature = false;
          }
        }
      }
    } finally {
      this.changing = false;
      this.notify();
    }
    return false;
  }
  dispose(): void { this.disposed = true; this.listGeneration++; this.selectionGeneration++; }
  private notify(): void { if (!this.disposed) this.redraw(); }
}
