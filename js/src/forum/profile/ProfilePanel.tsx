import Feedback from '../../common/components/Feedback';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import CharacterSheet from '../../common/character/CharacterSheet';
import {object, text, type Fields} from '../../common/catalogData';
import {uiIcon} from '../../common/uiIcons';
import CharacterEffectsModal from './CharacterEffectsModal';
import CharacterHistoryModal from './CharacterHistoryModal';
import ReviewPanel from './ReviewPanel';
import ProgressionTraining from './ProgressionTraining';
import CharacterManagementModal from './CharacterManagementModal';
import showModal from '../../common/showModal';
import type ProfileState from './ProfileState';
import CreationWizardModal from '../creation/CreationWizardModal';
import RoleplayerSummary from './RoleplayerSummary';
import LearningPanel from './LearningPanel';

interface Attrs {state: ProfileState; picker?: boolean; own?: boolean}
type ProfileTab = 'character' | 'training' | 'craft';
const tabs: {id: ProfileTab; label: string}[] = [
  {id: 'character', label: 'Персонаж'}, {id: 'training', label: 'Обучение'}, {id: 'craft', label: 'Крафт'},
];

/** Панель управляет навигацией и действиями; общая анкета только отображает персонажа. */
export default class ProfilePanel extends Component<Attrs> {
  selectedAttribute: number | null = null;
  selectedCharacter: number | null = null;
  selectedTab: ProfileTab = 'character';

  view(): Mithril.Children {
    const state = this.attrs.state, view = state.view;
    const character = object(view?.snapshot.character);
    if (this.selectedCharacter !== state.selected) {
      this.selectedCharacter = state.selected;
      this.selectedAttribute = null;
      this.selectedTab = 'character';
    }
    const active = text(character, 'status') === 'active';
    // Ожидающий проверки персонаж всегда открывает анкету, даже после отзыва активации.
    if (!active) this.selectedTab = 'character';
    const attribute = view?.development?.attributes.find(row => Number(row.id) === this.selectedAttribute && row.allowed);
    if (!attribute) this.selectedAttribute = null;

    const itemControl = (row: Fields) => {
      if (!view?.permissions.can_equip) return undefined;
      const equipped = Boolean(row.equipped);
      const forbidden = !equipped && Number(object(row.type).equipment_limit) === -1;
      return {
        disabled: state.actionsBlocked || forbidden,
        hint: forbidden ? 'Предметы этого типа нельзя экипировать' : undefined,
        // Отметка меняется после ответа сервера. Повтор использует ту же защищённую команду.
        toggle: () => void state.changeEquipment(Number(row.id), !equipped),
      };
    };
    const changesRequested = view?.review?.mechanics_status === 'changes_requested'
      || view?.review?.literature_status === 'changes_requested';
    return <div className="RpProfile">
      {state.loading ? <p role="status">Загрузка персонажей…</p> : <>
        {state.listError && <Feedback message={state.listError}
          refresh={{label: 'Обновить список персонажей', run: () => void state.loadList()}}/>}
        {state.roleplayer && <RoleplayerSummary stats={state.roleplayer}/>}
        {view && <ReviewPanel state={state}/>}
        {view && active && <nav className="RpProfile-tabs" aria-label="Разделы персонажа">
          {tabs.map(tab => <Button key={tab.id} type="button"
            className={'Button' + (this.selectedTab === tab.id ? ' is-active' : '')}
            aria-current={this.selectedTab === tab.id ? 'page' : undefined}
            disabled={state.changing} onclick={() => {this.selectedTab = tab.id;}}>{tab.label}</Button>)}
        </nav>}
        {this.toolbar()}
        {this.attrs.picker && !state.listError && !state.characters.length && <div className="RpProfile-empty">
          <h3>{this.attrs.own ? 'У вас пока нет персонажей' : 'Нет доступных для просмотра персонажей'}</h3>
          {this.attrs.own && app.forum.attribute('roleplayCanCreate') && <>
            <p>Мастер поможет выбрать расу, распределить характеристики и подобрать способности</p>
            <Button className="Button Button--primary" icon={uiIcon('actions.create')}
              onclick={() => void CreationWizardModal.open()}>Создать персонажа</Button>
          </>}
        </div>}
        {state.nextBefore !== null && <Button className="Button Button--link" loading={state.loadingMore}
          disabled={state.loadingMore || state.changing} onclick={() => void state.loadList(true)}>Показать ещё</Button>}
        {state.detailLoading && <p role="status">Загрузка анкеты…</p>}
        {state.detailError && <Feedback message={state.detailError}
          refresh={{label: 'Обновить анкету', run: () => {if (state.selected !== null) void state.select(state.selected);}}}/>}
        {state.actionError && <Feedback message={state.actionError} refresh={{
          label: state.retryAvailable ? 'Повторить действие' : 'Обновить анкету', disabled: state.changing,
          run: () => {if (state.retryAvailable) void state.retryOperation(); else if (state.selected !== null) void state.select(state.selected);},
        }}/>}
        {view && this.selectedTab === 'training' && <LearningPanel profile={state}/>}
        {view && this.selectedTab === 'craft' && <div className="RpProfile-placeholder" role="status">
          <strong>{tabs.find(tab => tab.id === this.selectedTab)?.label}</strong><p>В разработке</p>
        </div>}
        {view && this.selectedTab === 'character' && <CharacterSheet review={view}
          statusLabel={text(character, 'status') === 'creating' ? changesRequested ? 'Требуются исправления' : 'На проверке' : undefined}
          lookups={view.lookups} itemControl={itemControl}
          attributeControl={(id: number) => {
            const allowed = !state.actionsBlocked && Boolean(view.development?.attributes.find(row => Number(row.id) === id)?.allowed);
            return {allowed, selected: this.selectedAttribute === id,
              choose: () => {this.selectedAttribute = this.selectedAttribute === id ? null : id;}};
          }}
          attributeActions={<div className="RpDevelopment-confirm RpDevelopment-confirm--reserved">
            {attribute && <Button className="Button Button--primary"
              disabled={state.actionsBlocked} loading={state.changing} onclick={async () => {
                if (await state.develop('attribute', Number(attribute.id))) this.selectedAttribute = null;
              }}>Повысить · {Number(attribute.cost)} опыта</Button>}
          </div>}
          trainingContent={view.development ? <ProgressionTraining profile={state}/> : undefined}/>}
      </>}
    </div>;
  }

  /** Одна строка действий. Скрытые сервером данные истории не запрашиваются повторно. */
  private toolbar(): Mithril.Children {
    const state = this.attrs.state, view = state.view;
    const character = object(view?.snapshot.character);
    if (!view && !(this.attrs.picker && state.characters.length)) return null;
    return <div className="RpProfile-toolbar">
      {this.attrs.picker && state.characters.length > 0 && <div className="RpProfile-characterSelect">
        <select className="FormControl" aria-label="Выбрать персонажа" value={String(state.selected ?? '')}
          disabled={state.changing}
          onchange={(event: Event) => void state.select(Number((event.target as HTMLSelectElement).value))}>
          {state.characters.map(row => <option key={row.id} value={row.id}>{row.name}</option>)}
        </select>
        <Icon className="RpProfile-selectChevron" name={uiIcon('profile.selectChevron')}/>
      </div>}
      {view && Number(character.discussion_id) > 0 && <LinkButton className="Button Button--icon RpProfile-iconButton"
        icon={uiIcon('profile.literature')} title="Литературная анкета" aria-label="Литературная анкета"
        href={app.forum.attribute<string>('baseUrl') + '/d/' + Number(character.discussion_id)}/>}
      {view && (view.history ?? []).length > 0 && <Button className="Button Button--icon RpProfile-iconButton"
        icon={uiIcon('actions.history')} title="История персонажа" aria-label="История персонажа"
        disabled={state.changing} onclick={() => showModal(CharacterHistoryModal, {events: view.history ?? [], lookups: view.lookups})}/>}
      {view?.permissions.can_manage && <Button className="Button RpProfile-grant" disabled={state.actionsBlocked}
        onclick={() => showModal(CharacterManagementModal, {profile: state})}>Выдать</Button>}
      {view && <div className="RpProfile-toolbarEnd">
        <Button className="Button Button--icon RpProfile-iconButton" icon={uiIcon('profile.effects')} title="Эффекты" aria-label="Эффекты"
          disabled={state.changing} onclick={() => showModal(CharacterEffectsModal,
            {effects: Array.isArray(view.calculation.effects) ? view.calculation.effects as Fields[] : [],
              lookups: view.lookups})}/>
        <Button className="Button Button--icon RpProfile-iconButton" icon={uiIcon('actions.refresh')}
          title="Обновить анкету" aria-label="Обновить анкету" disabled={state.changing}
          onclick={() => state.selected !== null && void state.select(state.selected)}/>
      </div>}
    </div>;
  }
}
