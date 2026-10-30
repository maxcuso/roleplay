import {clearInputFeedback} from '../../common/messageKind';
import Component, {type ComponentAttrs} from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import SettingsState from './SettingsState';
import SettingsHeader from './SettingsHeader';
import SettingsFeedback from './SettingsFeedback';
import CreationSettingsApi, {type CreationSettingsView} from './CreationSettingsApi';
import LoadoutEditor from './LoadoutEditor';
import {FieldLabel} from '../../common/catalog/components/FormFields';
import {uiIcon, type UiIconName} from '../../common/uiIcons';

/** Сервер хранит все правила старта; эта форма не меняет уже созданных персонажей. */
export default class CreationSettingsPanel extends Component {
  settings!: SettingsState<CreationSettingsView>;
  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void {
    super.oninit(vnode);
    const api = new CreationSettingsApi();
    this.settings = new SettingsState(() => api.read(), data => api.save(data), () => m.redraw());
    void this.settings.load();
  }
  onremove(): void {this.settings.dispose();}
  view(): Mithril.Children {
    const state = this.settings, data = state.data, disabled = state.loading || state.saving;
    const config = data?.configuration;
    const changed = () => {state.saved = false;};
    // Короткие числовые поля остаются рядом с подписью, вместо растягивания на ширину колонки.
    const numberInput = (title: string, icon: UiIconName,
      key: 'max_characters' | 'starting_spell_count' | 'starting_gold' | 'starting_experience', min = 0, max = 100): Mithril.Children =>
      <label className="RpSettings-number"><FieldLabel label={title} icon={icon}/>
        <input className="FormControl" type="number" required min={min} max={max} step={1} value={config?.[key]}
          disabled={disabled} oninput={(event: Event) => {config![key] = Number((event.target as HTMLInputElement).value); changed();}}/>
      </label>;
    return <section className="RpSettings-panel">
      <SettingsHeader title="Создание персонажей" icon="wizard.title" caption="Настройки старта для новых персонажей."
        disabled={disabled} refresh={() => void state.load()}/>
      <SettingsFeedback state={state}/>
      {config && data && <form oninput={() => clearInputFeedback(state)} onchange={() => clearInputFeedback(state)} onsubmit={(event: Event) => {event.preventDefault(); void state.save();}}>
        <label className="RpSettings-toggle"><input type="checkbox" checked={config.enabled} disabled={disabled}
          onchange={(event: Event) => {config.enabled = (event.target as HTMLInputElement).checked; changed();}}/>
          Разрешить создание персонажей
        </label>
        <div className="RpSettings-numbers">
          {numberInput('Персонажей у игрока', 'sections.characters', 'max_characters', 1)}
          {numberInput('Заклинаний при доступном I круге', 'sections.spells', 'starting_spell_count')}
          {numberInput('Стартовое золото', 'resources.gold', 'starting_gold', 0, Number.MAX_SAFE_INTEGER)}
          {numberInput('Стартовый опыт', 'resources.activeExperience', 'starting_experience', 0, Number.MAX_SAFE_INTEGER)}
        </div>
        <div className="RpSettings-distribution"><FieldLabel label="Характеристики" icon="fields.attribute"/>
          <span>
            <b>{config.attribute_distribution.reduce((sum, value) => sum + value, 0)} очков</b>
            <b>{Math.min(...config.attribute_distribution)}–{Math.max(...config.attribute_distribution)}</b>
          </span>
        </div>
        <section className="RpSettings-block">
          <h3><FieldLabel label="Источники перков" icon="sections.perkSources"/></h3>
          <div className="RpSettings-grid">{(['race', 'skill'] as const).map(kind => <label key={kind} className="RpSettings-field">
            <FieldLabel label={kind === 'race' ? 'От расы' : 'От навыков'} icon={kind === 'race' ? 'sections.races' : 'sections.training'}/>
            <select className="FormControl" value={config.perk_sources[kind] ?? ''} disabled={disabled}
              onchange={(event: Event) => {
                const value = (event.target as HTMLSelectElement).value;
                if (value) config.perk_sources[kind] = Number(value); else delete config.perk_sources[kind];
                changed();
              }}>
              {[{id: 0, name: 'Не выбран', enabled: true}, ...(data.lookups.sources ?? [])].map(row =>
                <option key={row.id} value={row.id || ''}>{row.name}{row.enabled === false ? ' (выключен)' : ''}</option>)}
            </select>
          </label>)}</div>
        </section>
        <section className="RpSettings-block">
          <h3><FieldLabel label="Стартовое снаряжение" icon="sections.equipment"/></h3>
          <LoadoutEditor groups={config.loadout} items={data.lookups.items ?? []} disabled={disabled} changed={changed}/>
        </section>
        <footer className="RpSettings-footer">
          <small><Icon name={uiIcon('profile.literature')}/> Тег и шаблон — в «Анкеты и проверка».</small>
          <Button type="submit" className="Button Button--primary" icon={uiIcon('actions.save')} disabled={disabled || state.stale}
            loading={state.saving}>Сохранить изменения</Button>
        </footer>
      </form>}
    </section>;
  }
}
