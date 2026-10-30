import Feedback from '../../common/components/Feedback';
import app from 'flarum/forum/app';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type ProfileState from './ProfileState';
import {object, text, lookup, type Fields, type Lookups, type CatalogRow} from '../../common/catalogData';
import {requestMessage} from '../../common/requestError';
import {roman, safeImage, pathColor} from '../../common/presentation';
import {catalogueIcon, uiIcon, type UiIconName} from '../../common/uiIcons';
import showModal from '../../common/showModal';
import CatalogEditModal from '../../common/catalog/components/CatalogEditModal';
import perkDefinition from '../../common/catalog/definitions/Perks';
import spellDefinition from '../../common/catalog/definitions/Spells';
import itemDefinition from '../../common/catalog/definitions/Items';
import ManagementCatalogApi from './ManagementCatalogApi';
import RankLabel, {rankText} from '../../common/character/RankLabel';

interface Attrs extends IInternalModalAttrs {profile: ProfileState}
type Category = 'resources' | 'perk' | 'spell' | 'item' | 'rank';
const tabs: {key: Category; label: string; icon: UiIconName}[] = [
  {key: 'resources', label: 'Ресурсы', icon: 'resources.activeExperience'},
  {key: 'perk', label: 'Перки', icon: 'sections.perks'},
  {key: 'spell', label: 'Заклинания', icon: 'sections.spells'},
  {key: 'item', label: 'Предметы', icon: 'sections.inventory'},
  {key: 'rank', label: 'Ранг', icon: 'management.rank'},
];
const definitions = {perk: perkDefinition, spell: spellDefinition, item: itemDefinition};

/** Черновик остаётся в нижнем окне: общий редактор открывается поверх него и не уничтожает причину выдачи. */
export default class CharacterManagementModal extends Modal<Attrs> {
  characterId = 0;
  category: Category = 'resources'; removing = false;
  experience = 0; gold = 0; quantity = 1; targetId = 0; sourceId = 0; raceId = 0; skillId = 0;
  questName = ''; eventName = ''; comment = ''; search = '';
  lookups: Lookups = {}; loading = true; loadError = ''; disposed = false; creating = false;
  private revealId = 0;
  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.characterId = this.attrs.profile.selected!;
    this.raceId = Number(object(object(this.attrs.profile.view!.snapshot.character).race).id);
    void this.load();
  }
  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {super.onremove(vnode); this.disposed = true;}
  protected modalClassName(): string {return 'RpManagementModal';}
  protected modalHeading(): ModalHeading {return {label: 'Выдача персонажу', purpose: 'grant'};}
  get action(): string {return this.category === 'rank' ? 'set_rank' : this.category === 'resources' ? 'reward' : (this.removing ? 'remove_' : 'grant_') + this.category;}
  private get disabled(): boolean {return this.loading || this.creating || this.attrs.profile.actionsBlocked;}
  private sameCharacter(): boolean {
    return !this.disposed && this.attrs.profile.selected === this.characterId;
  }
  private available(): boolean {
    return this.sameCharacter() && Boolean(this.attrs.profile.view?.permissions.can_manage);
  }
  /** После конфликта серверных версий заново читаем права и варианты; черновик выдачи сохраняется. */
  private async refreshProfile(): Promise<void> {
    if (!this.sameCharacter() || this.attrs.profile.actionsBlocked || this.loading) return;
    this.loading = true; this.loadError = '';
    try {
      await this.attrs.profile.refreshAfterCatalogChange();
      if (this.available()) {
        this.attrs.profile.actionError = '';
        await this.load();
      }
    } catch (error) {
      this.loadError = requestMessage(error, 'Не удалось обновить анкету. Попробуйте ещё раз');
    } finally {this.loading = false; m.redraw();}
  }
  private async load(): Promise<void> {
    this.loading = true; this.loadError = '';
    try {
      const result = await app.request<{data: {revision: number; lookups: Lookups}}>({method: 'GET',
        url: app.forum.attribute<string>('apiUrl') + '/roleplay/characters/' + this.characterId + '/management', errorHandler: () => {}});
      // Повтор загрузки после создания должен обновить и версию анкеты, иначе выдача получит конфликт справочников.
      if (this.available() && Number(this.attrs.profile.view!.snapshot.catalog_revision) !== result.data.revision) {
        await this.attrs.profile.refreshAfterCatalogChange();
      }
      if (this.available()) this.lookups = result.data.lookups;
    } catch (error) {if (!this.disposed) this.loadError = requestMessage(error, 'Не удалось загрузить варианты выдачи. Попробуйте ещё раз');}
    finally {if (!this.disposed) {this.loading = false; m.redraw();}}
  }
  private chooseCategory(category: Category): void {
    this.category = category;
    this.targetId = category === 'rank' ? Number(object(this.attrs.profile.view!.snapshot.character).rank_id ?? 0) : 0;
    this.search = ''; this.quantity = 1;
  }
  private changeMode(removing: boolean): void {this.removing = removing; this.targetId = 0; this.quantity = 1;}

  content(): Mithril.Children {
    const state = this.attrs.profile, view = state.view;
    if (!this.available() || !view) return <div className="Modal-body">
      <Feedback message={state.actionError || 'Эту анкету больше нельзя изменить. Попробуйте обновить страницу, чтобы проверить её состояние'}/>
      {this.loadError && <Feedback message={this.loadError}/>}
      {this.sameCharacter() && <Button type="button" className="Button Button--primary" icon={uiIcon('actions.refresh')}
        disabled={this.loading || state.actionsBlocked} loading={this.loading}
        onclick={() => void this.refreshProfile()}>Обновить анкету</Button>}
    </div>;
    const character = object(view.snapshot.character);
    return <form className="Modal-body RpManagement" oninput={() => state.clearInputError()} onchange={() => state.clearInputError()}
      onsubmit={(event: Event) => {event.preventDefault(); void this.save();}}>
      <p className="RpManagement-character">{text(character, 'first_name')} {text(character, 'last_name')}</p>
      <div className="RpManagement-tabs" role="tablist" aria-label="Что изменить">
        {tabs.map(tab => <Button key={tab.key} type="button" className="Button" role="tab" aria-selected={String(this.category === tab.key)}
          disabled={this.disabled} onclick={() => this.chooseCategory(tab.key)}><Icon name={uiIcon(tab.icon)}/><span>{tab.label}</span></Button>)}
      </div>
      {this.loading && <p role="status">Загрузка…</p>}
      {this.loadError && <Feedback message={this.loadError}
        refresh={{label: 'Обновить варианты выдачи', run: () => void this.load(), disabled: this.loading}}/>}
      <div>
        {this.category === 'rank' ? this.ranks() : <>
        <div className="RpManagement-mode" aria-label="Операция">
          <Button type="button" className="Button" icon={uiIcon('actions.create')} aria-pressed={String(!this.removing)} disabled={this.disabled}
            onclick={() => this.changeMode(false)}>{this.category === 'resources' ? 'Начислить' : 'Выдать'}</Button>
          <Button type="button" className="Button" icon={uiIcon('management.remove')} aria-pressed={String(this.removing)} disabled={this.disabled}
            onclick={() => this.changeMode(true)}>{this.category === 'resources' ? 'Списать' : 'Изъять'}</Button>
        </div>
        {this.category === 'resources' ? this.resources() : this.entities()}
        </>}
        <label className="RpManagement-field RpManagement-reason"><span>Причина</span>
          <textarea className="FormControl" required maxlength={4000} rows={2} value={this.comment} disabled={this.disabled}
            placeholder="Например: награда за квест" oninput={(event: Event) => {this.comment = (event.target as HTMLTextAreaElement).value;}}/>
        </label>
        {state.actionError && <Feedback message={state.actionError}/>}
        <div className="RpManagement-footer">
          {state.retryAvailable ? <Button type="button" className="Button Button--primary" disabled={state.changing} loading={state.changing}
            onclick={async () => {if (await state.retryOperation()) this.complete();}}>Повторить действие</Button>
            : <Button type="submit" className={'Button Button--primary' + (this.removing && this.category !== 'rank' ? ' RpManagement-remove' : '')}
              icon={uiIcon(this.category === 'rank' ? 'management.rank' : this.removing ? 'management.remove' : 'actions.create')}
              disabled={!this.canSave()} loading={state.changing}>{this.submitLabel()}</Button>}
        </div>
      </div>
    </form>;
  }
  /** Ранг заменяется одной командой; «Без ранга» снимает назначение, а не удаляет справочник. */
  private ranks(): Mithril.Children {
    const current = object(object(this.attrs.profile.view!.snapshot.character).rank);
    const entries = this.lookups.ranks ?? [];
    const selected = lookup(this.lookups, 'ranks', this.targetId)
      ?? (Number(current.id) === this.targetId ? current : undefined);
    const label = (rank: Fields): Mithril.Children => rank.name ? <RankLabel rank={rank}/> : 'Без ранга';
    // Один плоский список: Mithril не допускает смешивания keyed-узлов и фрагментов без ключа.
    const options = [<option key="empty" value={0}>Без ранга</option>];
    if (current.id && !entries.some(row => row.id === Number(current.id))) {
      options.push(<option key="current" value={Number(current.id)} disabled>{rankText(current)} (выключен)</option>);
    }
    options.push(...entries.map(row => <option key={row.id} value={row.id} dir="ltr">{roman(Number(row.level))} · {rankText(row)}</option>));
    return <div className="RpManagement-rank">
      <label className="RpManagement-field"><span><Icon name={uiIcon('management.rank')}/> Ранг</span>
        <select className="FormControl" aria-label="Ранг" value={this.targetId} disabled={this.disabled}
          onchange={(event: Event) => {this.targetId = Number((event.target as HTMLSelectElement).value);}}>
          {options}
        </select>
      </label>
      <div className="RpManagement-rankPreview" aria-live="polite">
        {label(current)}<Icon name={uiIcon('management.result')}/>{label(object(selected))}
      </div>
      {selected?.description && <p className="helpText">{String(selected.description)}</p>}
    </div>;
  }
  /** Суммы вводятся положительными, знак задаёт режим. Предпросмотр ничего не списывает. */
  private resources(): Mithril.Children {
    const balances = object(this.attrs.profile.view!.snapshot.balances);
    return <div className="RpManagement-resources">
      {([{field: 'experience', key: 'experience_active', label: 'Опыт', icon: 'resources.activeExperience'},
        {field: 'gold', key: 'gold', label: 'Золото', icon: 'resources.gold'}] as const).map(resource => {
        const before = Number(balances[resource.key] ?? 0), amount = this[resource.field], after = before + (this.removing ? -amount : amount);
        return <label key={resource.field} className="RpManagement-field"><span><Icon name={uiIcon(resource.icon)}/> {resource.label}</span>
          <input className="FormControl" aria-label={resource.label} type="number" min={0} max={Number.MAX_SAFE_INTEGER} step={1} required
            value={amount} disabled={this.disabled} oninput={(event: Event) => {this[resource.field] = Number((event.target as HTMLInputElement).value);}}/>
          <span className={'RpManagement-result' + (after < 0 ? ' is-invalid' : '')} aria-live="polite">
            {before} <Icon name={uiIcon('management.result')}/> <strong>{Number.isSafeInteger(after) ? after : '—'}</strong>
          </span>
        </label>;
      })}
      {this.removing && !this.validResources() && <Feedback message="Проверьте указанное количество и доступные ресурсы персонажа" hint/>}
    </div>;
  }
  private select(title: string, value: number, entries: CatalogRow[], set: (id: number) => void): Mithril.Children {
    return <label className="RpManagement-field"><span>{title}</span><select className="FormControl" value={value} disabled={this.disabled}
      onchange={(event: Event) => set(Number((event.target as HTMLSelectElement).value))}>
      {[{id: 0, name: 'Выберите…'}, ...entries].map(row => <option key={row.id} value={row.id}>{row.name}</option>)}
    </select></label>;
  }
  private entities(): Mithril.Children {
    const rows = this.rows().filter(row => row.name.toLocaleLowerCase('ru').includes(this.search.trim().toLocaleLowerCase('ru')));
    const noun = {perk: 'перков', spell: 'заклинаний', item: 'предметов'}[this.category as 'perk' | 'spell' | 'item'];
    return <div>
      <div className="RpManagement-listToolbar">
        <input className="FormControl" type="search" aria-label={'Поиск ' + noun} value={this.search} placeholder="Поиск по названию…"
          disabled={this.disabled} oninput={(event: Event) => {this.search = (event.target as HTMLInputElement).value;}}/>
        <Button type="button" className="Button Button--primary" icon={uiIcon('actions.create')} disabled={this.disabled}
          loading={this.creating} onclick={() => void this.createEntity()}>Создать</Button>
      </div>
      <div className="RpManagement-options" aria-label={'Список ' + noun}>
        {rows.map(row => <button key={row.id} type="button" className="RpManagement-option" aria-pressed={String(row.id === this.targetId)}
          oncreate={(vnode: Mithril.VnodeDOM) => this.revealCreated(row.id, vnode.dom as HTMLElement)}
          onupdate={(vnode: Mithril.VnodeDOM) => this.revealCreated(row.id, vnode.dom as HTMLElement)}
          disabled={this.disabled} onclick={() => {this.targetId = row.id;}}>
          {this.entityIcon(row)}<span><strong>{row.name}</strong>{row.subtitle && <small>{String(row.subtitle)}</small>}</span>
          {row.id === this.targetId && <Icon className="RpManagement-selected" name={uiIcon('wizard.review')}/>}
        </button>)}
      </div>
      {!rows.length && !this.loading && <p className="helpText">Ничего не найдено.</p>}
      {this.category === 'item' && <label className="RpManagement-field"><span>Количество</span>
        <input className="FormControl" type="number" required min={1} max={this.removing ? Number(this.rows().find(row => row.id === this.targetId)?.quantity ?? 1000) : 1000}
          step={1} value={this.quantity} disabled={this.disabled} oninput={(event: Event) => {this.quantity = Number((event.target as HTMLInputElement).value);}}/></label>}
      {this.category === 'perk' && !this.removing && this.sourceFields()}
    </div>;
  }
  private sourceFields(): Mithril.Children {
    const dependencies = this.dependencies();
    const nameField = (title: string, field: 'questName' | 'eventName'): Mithril.Children =>
      <label className="RpManagement-field"><span>{title}</span><input className="FormControl" required maxlength={100} value={this[field]}
        disabled={this.disabled} oninput={(event: Event) => {this[field] = (event.target as HTMLInputElement).value;}}/></label>;
    return <div className="RpManagement-source">
      {this.select('Источник', this.sourceId, this.lookups.sources ?? [], id => {this.sourceId = id;})}
      {dependencies.has('race') && this.select('Раса источника', this.raceId, this.lookups.races ?? [], id => {this.raceId = id;})}
      {dependencies.has('skill') && this.select('Навык источника', this.skillId, this.lookups.skills ?? [], id => {this.skillId = id;})}
      {dependencies.has('quest') && nameField('Название квеста', 'questName')}
      {dependencies.has('event') && nameField('Название ивента', 'eventName')}
    </div>;
  }
  private dependencies(): Set<unknown> {
    const source = lookup(this.lookups, 'sources', this.sourceId);
    return new Set([source?.name_source, source?.icon_source]);
  }
  /** Новая запись может находиться в конце длинного списка. Прокручиваем только список, без скачка всего окна. */
  private revealCreated(id: number, element: HTMLElement): void {
    if (id !== this.revealId) return;
    const list = element.closest('.RpManagement-options');
    if (list) list.scrollTop += element.getBoundingClientRect().top - list.getBoundingClientRect().top;
    this.revealId = 0;
  }
  private rows(): CatalogRow[] {
    if (this.removing) return this.owned();
    const kind = this.category, owned = new Set(this.owned().map(row => row.id));
    return (this.lookups[kind + 's'] ?? []).filter(row => (kind === 'item' || !owned.has(row.id))
      && (kind !== 'spell' || Boolean(lookup(this.lookups, 'paths', row.magic_path_id))))
      .map(row => {
        const reference = lookup(this.lookups, kind === 'spell' ? 'paths' : 'item-types', kind === 'spell' ? row.magic_path_id : row.item_type_id);
        return {...row, name: row.name + (kind === 'spell' ? ' ' + roman(Number(row.level)) : ''),
          subtitle: reference?.name, icon: reference?.icon, color: kind === 'spell' ? reference?.color : undefined};
      });
  }
  private owned(): CatalogRow[] {
    const snapshot = this.attrs.profile.view!.snapshot;
    const entries = (this.category === 'item' ? snapshot.inventory : snapshot[this.category + 's']) as Fields[] | undefined;
    return (entries ?? []).map(row => {
      const definition = object(row.definition), reference = object(this.category === 'spell' ? row.path : row.type);
      return {id: Number(row.id), name: text(row, 'name') + (this.category === 'spell' ? ' ' + roman(Number(definition.level)) : ''),
        image: definition.image, icon: reference.icon, color: this.category === 'spell' ? reference.color : undefined,
        quantity: row.quantity, subtitle: this.category === 'item' ? '×' + row.quantity + (row.equipped ? ' · экипирован' : '') : reference.name};
    });
  }
  private entityIcon(row: CatalogRow): Mithril.Children {
    const image = this.category === 'perk' ? safeImage(text(row, 'image')) : '';
    return image ? <img src={image} alt=""/> : <Icon name={row.icon ? catalogueIcon(text(row, 'icon')) : uiIcon(tabs.find(tab => tab.key === this.category)!.icon)}
      style={{color: row.color ? pathColor(text(row, 'color')) : undefined}}/>;
  }
  private validResources(): boolean {
    const balances = object(this.attrs.profile.view!.snapshot.balances);
    return [['experience', 'experience_active'], ['gold', 'gold']].every(([field, key]) => {
      const amount = this[field as 'experience' | 'gold'], after = Number(balances[key] ?? 0) + (this.removing ? -amount : amount);
      return Number.isSafeInteger(amount) && amount >= 0 && Number.isSafeInteger(after) && after >= 0;
    });
  }
  private canSave(): boolean {
    if (this.disabled || this.loadError || !this.comment.trim()) return false;
    if (this.category === 'rank') {
      const current = Number(object(this.attrs.profile.view!.snapshot.character).rank_id ?? 0);
      return this.targetId !== current && (this.targetId === 0 || Boolean(lookup(this.lookups, 'ranks', this.targetId)));
    }
    if (this.category === 'resources') return (this.experience > 0 || this.gold > 0) && this.validResources();
    const target = this.rows().find(row => row.id === this.targetId);
    if (!target) return false;
    if (this.category === 'item' && (!Number.isInteger(this.quantity) || this.quantity < 1 || this.quantity > 1000
      || this.removing && this.quantity > Number(target.quantity))) return false;
    if (this.category === 'perk' && !this.removing) {
      if (!lookup(this.lookups, 'sources', this.sourceId)) return false;
      const needs = this.dependencies();
      if (needs.has('race') && !lookup(this.lookups, 'races', this.raceId) || needs.has('skill') && !lookup(this.lookups, 'skills', this.skillId)
        || needs.has('quest') && !this.questName.trim() || needs.has('event') && !this.eventName.trim()) return false;
    }
    return true;
  }
  private submitLabel(): string {
    if (this.category === 'rank') return this.targetId ? 'Назначить ранг' : 'Снять ранг';
    if (this.category === 'resources') return this.removing ? 'Списать ресурсы' : 'Начислить награду';
    return (this.removing ? 'Изъять ' : 'Выдать ') + {perk: 'перк', spell: 'заклинание', item: 'предмет', rank: 'ранг'}[this.category];
  }
  /** Только после подтверждённого ответа сервера, в том числе при безопасном повторе. */
  private complete(): void {
    const message = this.category === 'rank' ? 'Ранг персонажа обновлён'
      : this.category === 'resources' ? (this.removing ? 'Ресурсы списаны' : 'Ресурсы начислены персонажу')
      : this.removing ? {perk: 'Перк изъят', spell: 'Заклинание изъято', item: 'Предмет изъят'}[this.category]
      : {perk: 'Перк выдан персонажу', spell: 'Заклинание выдано персонажу', item: 'Предмет выдан персонажу'}[this.category];
    this.hide();
    app.alerts.show({type: 'success'}, message);
  }
  private async save(): Promise<void> {
    if (!this.canSave() || !this.available()) return;
    const sign = this.removing ? -1 : 1;
    const data: Fields = this.category === 'rank' ? {rank_id: this.targetId || null}
      : this.category === 'resources' ? {experience: sign * this.experience, gold: sign * this.gold} : {target_id: this.targetId};
    if (this.category === 'item') data.quantity = this.quantity;
    if (this.category === 'perk' && !this.removing) {
      const needs = this.dependencies(), context: Fields = {};
      if (needs.has('race')) context.race_id = this.raceId;
      if (needs.has('skill')) context.skill_id = this.skillId;
      if (needs.has('quest')) context.quest_name = this.questName.trim();
      if (needs.has('event')) context.event_name = this.eventName.trim();
      data.source_id = this.sourceId; data.context = context;
    }
    if (await this.attrs.profile.manage(this.action, data, this.comment.trim())) this.complete();
  }
  private async createEntity(): Promise<void> {
    if (this.disabled || !this.available() || this.category === 'rank' || this.category === 'resources') return;
    const kind = this.category, definition = definitions[kind];
    const api = new ManagementCatalogApi(definition.key as 'perks' | 'spells' | 'items');
    this.creating = true; this.loadError = '';
    try {
      const data = await api.list();
      if (!this.available()) return;
      showModal(CatalogEditModal, {definition, row: null, data, api, onSaved: async () => {
        if (!this.available()) return;
        this.loading = true;
        try {
          await this.attrs.profile.refreshAfterCatalogChange();
          await this.load();
          if (this.available() && this.category === kind) {
            this.removing = false; this.search = ''; this.targetId = api.createdId ?? 0; this.revealId = this.targetId;
          }
        } catch (error) {this.loadError = requestMessage(error, 'Запись создана, но не удалось обновить список. Загрузите список ещё раз');}
        finally {this.loading = false; m.redraw();}
      }}, true);
    } catch (error) {if (!this.disposed) this.loadError = requestMessage(error, 'Не удалось открыть редактор. Попробуйте ещё раз');}
    finally {if (!this.disposed) {this.creating = false; m.redraw();}}
  }
}
