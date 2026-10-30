import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Feedback from '../../common/components/Feedback';
import type Mithril from 'mithril';
import {text, type CatalogRow} from '../../common/catalogData';
import {catalogueIcon, uiIcon, type UiIconName} from '../../common/uiIcons';
import {pluralForm, roman, safeImage} from '../../common/presentation';
import EpisodeCompletionState from './EpisodeCompletionState';
import type {CharacterRewardDraft, CompletionContext, CompletionDataSource, CompletionPayload, RewardKind} from './completionTypes';

interface Attrs extends IInternalModalAttrs {
  context: CompletionContext;
  source: CompletionDataSource;
  onInsert: (payload: CompletionPayload) => void | Promise<void>;
}
const kinds: Array<{key: RewardKind; label: string; search: string; icon: UiIconName}> = [
  {key: 'items', label: 'Предмет', search: 'Поиск предметов', icon: 'sections.items'},
  {key: 'perks', label: 'Перк', search: 'Поиск перков', icon: 'sections.perks'},
  {key: 'spells', label: 'Заклинание', search: 'Поиск заклинаний', icon: 'sections.spells'},
];
const numericValue = (event: Event): number => {
  const value = (event.target as HTMLInputElement).value;
  return value === '' ? NaN : Number(value);
};
const numericLabel = (value: number | null): string => value !== null && Number.isFinite(value) ? value.toLocaleString('ru-RU') : '—';

/** Форма на штатном Modal Flarum. Контекст и сохранение настройки передаёт
 * кнопка редактора. Окно вставляет BB-код и не выполняет выдачу/закрытие:
 * эти действия разрешены только серверному обработчику публикации.
 */
export default class EpisodeCompletionModal extends Modal<Attrs> {
  completion!: EpisodeCompletionState;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.completion = new EpisodeCompletionState(this.attrs.context, this.attrs.source);
  }
  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    this.completion.dispose(); super.onremove(vnode);
  }
  protected modalClassName(): string {return 'Modal--large RpEpisodeCompletionModal';}
  protected modalHeading(): ModalHeading {return {label: 'Завершение эпизода', purpose: 'complete'};}

  hide(): void {if (!this.completion.busy) super.hide();}

  content(): Mithril.Children {
    const state = this.completion;
    return <form className="RpEpisodeCompletion" onsubmit={(event: Event) => {
      event.preventDefault(); void this.insert();
    }}>
      <p className="RpEpisodeCompletion-subtitle">{state.context.title} · {state.recipients.length}{' '}
        {pluralForm(state.recipients.length, ['получатель', 'получателя', 'получателей'])}</p>
      <div className="Modal-body RpEpisodeCompletion-body">
        <div className="RpEpisodeCompletion-layout">
          <nav className="RpEpisodeCompletion-roster" aria-label="Получатели наград">
            {state.recipients.map(recipient => this.person(recipient))}
            <Button type="button" className="Button" icon={uiIcon('permissions.create')} disabled={state.busy}
              onclick={() => state.openPicker('characters')}>Добавить персонажа</Button>
          </nav>
          <div className="RpEpisodeCompletion-editor">
            {state.selected ? this.editor(state.selected) : <p className="helpText">Добавьте персонажа для назначения награды</p>}
            {state.picker && this.picker()}
          </div>
        </div>
        {state.error && <Feedback message={state.error} kind={state.errorKind}/>}
      </div>
      <div className="Modal-footer RpEpisodeCompletion-footer">
        <p className="helpText">{state.context.isQuest ? 'Награды будут выданы, а тема закрыта после публикации сообщения' : 'Награды будут выданы после публикации сообщения'}</p>
        <div className="RpEpisodeCompletion-footerActions">
          <Button type="button" className="Button" disabled={state.busy} onclick={() => this.hide()}>Отмена</Button>
          <Button type="submit" className="Button Button--primary" icon={uiIcon('transfer.file')}
            loading={state.inserting} disabled={state.busy || !state.recipients.length}>Вставить код</Button>
        </div>
      </div>
    </form>;
  }

  private person(recipient: CharacterRewardDraft): Mithril.Children {
    const state = this.completion, character = recipient.character;
    const avatar = safeImage(character.avatarUrl ?? '');
    return <div key={character.id} className="RpEpisodeCompletion-person">
      <button type="button" className="RpEpisodeCompletion-personSelect" aria-pressed={String(state.selectedId === character.id)}
        disabled={state.busy} onclick={() => state.select(character.id)}>
        <span className="RpEpisodeCompletion-avatar" aria-hidden="true">
          {avatar ? <img src={avatar} alt=""/> : character.name.slice(0, 1).toLocaleUpperCase('ru-RU')}
        </span>
        <span className="RpEpisodeCompletion-personCopy"><strong>{character.name}</strong>
          <small>{character.available
            ? numericLabel(state.totalExperience(recipient)) + ' опыта · ' + numericLabel(recipient.gold) + ' золота'
            : 'Персонаж недоступен'}</small></span>
      </button>
      <Button type="button" className="Button Button--icon Button--link RpEpisodeCompletion-removePerson"
        icon={uiIcon('actions.close')} disabled={state.busy} title={'Исключить из наград: ' + character.name}
        aria-label={'Исключить из наград: ' + character.name} onclick={() => state.remove(character.id)}/>
    </div>;
  }

  private editor(recipient: CharacterRewardDraft): Mithril.Children {
    const state = this.completion;
    return <section aria-label={'Награда: ' + recipient.character.name}>
      <h4 className="RpEpisodeCompletion-name">{recipient.character.name}</h4>
      {!recipient.character.available && <Feedback message="Этот персонаж недоступен для выдачи награды. Выберите другого получателя" hint/>}
      <div className="RpEpisodeCompletion-fields">
        {([{key: 'experience', label: 'Базовый опыт', icon: 'resources.activeExperience'},
          {key: 'gold', label: 'Золото', icon: 'resources.gold'}] as const).map(field => <label key={field.key}>
          <span><Icon name={uiIcon(field.icon)}/> {field.label}</span>
          <input className="FormControl" type="number" min={0} max={Number.MAX_SAFE_INTEGER} step={1} required
            aria-label={field.label + ': ' + recipient.character.name} value={Number.isNaN(recipient[field.key]) ? '' : recipient[field.key]}
            disabled={state.busy || !recipient.character.available} oninput={(event: Event) => {
              recipient[field.key] = numericValue(event); if (state.errorKind === 'input') state.error = '';
            }}/>
        </label>)}
      </div>
      <div className="RpEpisodeCompletion-calculation">
        {recipient.character.bonuses.length ? recipient.character.bonuses.map((bonus, index) => <div key={index}>
          <span>{bonus.name}</span><span>{bonus.percent > 0 ? '+' : ''}{bonus.percent}%</span>
        </div>) : <div><span>Бонусы к опыту</span><span>Нет</span></div>}
        <div className="RpEpisodeCompletion-total" aria-live="polite"><strong>Опыт с бонусами</strong>
          <strong>{numericLabel(state.totalExperience(recipient))}</strong></div>
        <p className="helpText">Бонусы учитываются на момент публикации</p>
      </div>
      <p className="RpEpisodeCompletion-sectionLabel">Другие награды</p>
      <div className="RpEpisodeCompletion-rewards">
        {kinds.flatMap(kind => recipient[kind.key].map(reward => <div key={kind.key + ':' + reward.row.id} className="RpEpisodeCompletion-reward">
          {this.glyph(kind.key, reward.row)}
          <span><strong>{this.rewardName(kind.key, reward.row)}{kind.key === 'items' ? ' × ' + reward.quantity : ''}</strong>
            {state.duplicate(recipient, kind.key, reward.row.id) && <small>Уже есть у персонажа — будет пропущено</small>}</span>
          <Button type="button" className="Button Button--icon Button--link" icon={uiIcon('actions.close')}
            title={'Убрать награду: ' + reward.row.name} aria-label={'Убрать награду: ' + reward.row.name}
            disabled={state.busy} onclick={() => state.removeReward(kind.key, reward.row.id)}/>
        </div>))}
      </div>
      <div className="RpEpisodeCompletion-add">
        {kinds.map(kind => <Button key={kind.key} type="button" className="Button" icon={uiIcon('actions.create')}
          disabled={state.busy || !recipient.character.available} onclick={() => state.openPicker(kind.key)}>{kind.label}</Button>)}
      </div>
    </section>;
  }

  private rewardName(kind: RewardKind | 'characters', row: CatalogRow): string {
    return row.name + (kind === 'spells' ? ' ' + roman(Number(row.level)) : '');
  }

  private glyph(kind: RewardKind | 'characters', row: CatalogRow): Mithril.Children {
    const image = safeImage(text(row, kind === 'characters' ? 'avatarUrl' : 'image'));
    const icon = text(row, 'icon') || uiIcon(kind === 'characters' ? 'sections.characters' : kinds.find(candidate => candidate.key === kind)!.icon);
    return <span className="RpEpisodeCompletion-glyph" aria-hidden="true">
      {image ? <img src={image} alt=""/> : <Icon name={catalogueIcon(icon)}/>}
    </span>;
  }

  private picker(): Mithril.Children {
    const state = this.completion, picker = state.picker!;
    const list = picker.list, title = picker.kind === 'characters' ? 'Поиск персонажей' : kinds.find(kind => kind.key === picker.kind)!.search;
    return <section className="RpEpisodeCompletion-picker" aria-label={title}>
      <label className="RpEpisodeCompletion-search"><span>{title}</span>
        <input className="FormControl" type="search" value={list.search} placeholder="Поиск по названию"
          aria-label={title} disabled={state.busy} oninput={(event: Event) => state.search((event.target as HTMLInputElement).value)}/>
      </label>
      {list.error && <Feedback message={list.error}
        refresh={{label: 'Повторить поиск', disabled: list.loading, run: () => void list.load()}}/>}
      <div className="RpEpisodeCompletion-results" aria-busy={String(list.loading)}>
        {list.loading ? <p className="helpText" role="status">Поиск…</p> : list.error ? null : list.data.rows.length
          ? list.data.rows.map(row => <button key={row.id} type="button" className="RpEpisodeCompletion-option"
            disabled={state.busy || row.enabled === false} aria-pressed={String(picker.selected?.id === row.id)}
            onclick={() => state.choose(row)}>{this.glyph(picker.kind, row)}
            <span>{this.rewardName(picker.kind, row)}</span>
            {picker.selected?.id === row.id && <Icon name={uiIcon('actions.select')}/>}
          </button>) : <p className="helpText" role="status">Ничего не найдено</p>}
      </div>
      {!list.error && list.data.total > list.data.per_page && <div className="RpEpisodeCompletion-pagination">
        <Button type="button" className="Button Button--icon" icon={uiIcon('actions.moveUp')}
          aria-label="Предыдущая страница" title="Предыдущая страница" disabled={state.busy || list.loading || list.page <= 1}
          onclick={() => void state.page(-1)}/>
        <span className="helpText" role="status">{list.page} / {Math.ceil(list.data.total / list.data.per_page)}</span>
        <Button type="button" className="Button Button--icon" icon={uiIcon('actions.moveDown')}
          aria-label="Следующая страница" title="Следующая страница"
          disabled={state.busy || list.loading || list.page * list.data.per_page >= list.data.total} onclick={() => void state.page(1)}/>
      </div>}
      {picker.kind === 'items' && <label className="RpEpisodeCompletion-quantity"><span>Количество</span>
        <input className="FormControl" type="number" min={1} max={1000} step={1} required aria-label="Количество предметов"
          disabled={state.busy} value={Number.isNaN(picker.quantity) ? '' : picker.quantity}
          oninput={(event: Event) => {picker.quantity = numericValue(event); state.error = '';}}/>
      </label>}
      <div className="RpEpisodeCompletion-pickerActions">
        <Button type="button" className="Button" disabled={state.busy} onclick={() => state.closePicker()}>Отмена</Button>
        <Button type="button" className="Button Button--primary" loading={state.choosingCharacter}
          disabled={state.busy || list.loading || !!list.error || !picker.selected} onclick={() => void state.addChoice()}>Добавить</Button>
      </div>
    </section>;
  }

  private async insert(): Promise<void> {
    if (await this.completion.insert(this.attrs.onInsert)) this.hide();
  }
}
