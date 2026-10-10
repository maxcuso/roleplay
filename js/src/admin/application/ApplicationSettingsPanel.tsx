import Component, {type ComponentAttrs} from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import ApplicationSettingsState from './ApplicationSettingsState';
import ApplicationSettingsApi from './ApplicationSettingsApi';
import SettingsHeader from '../settings/SettingsHeader';
import SettingsFeedback from '../settings/SettingsFeedback';
import {FieldLabel} from '../../common/catalog/components/FormFields';
import {uiIcon} from '../../common/uiIcons';

/** Тег и литературный шаблон редактируются отдельно от игровых справочников. */
export default class ApplicationSettingsPanel extends Component {
  settings!: ApplicationSettingsState;
  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void {
    super.oninit(vnode);
    this.settings = new ApplicationSettingsState(new ApplicationSettingsApi(), () => m.redraw());
    void this.settings.load();
  }
  onremove(): void {this.settings.dispose();}
  view(): Mithril.Children {
    const state = this.settings, data = state.data, disabled = state.loading || state.saving;
    return <section className="RpSettings-panel RpSettings-panel--application">
      <SettingsHeader title="Анкеты и проверка" icon="review.application"
        caption="Тема создаётся автоматически; литературную часть отправляет игрок." disabled={disabled} refresh={() => void state.load()}/>
      <SettingsFeedback state={state}/>
      {data && <form onsubmit={(event: Event) => {event.preventDefault(); void state.save();}}>
        <div className="RpSettings-grid">
          <label className="RpSettings-field"><FieldLabel label="Тег анкет" icon="fields.name"/>
            <select className="FormControl" value={String(data.application.tag_id ?? '')} disabled={disabled}
              onchange={(event: Event) => {const value = (event.target as HTMLSelectElement).value;
                data.application.tag_id = value ? Number(value) : null; state.saved = false;}}>
              {[{id: 0, name: 'Не выбран'}, ...data.tags].map(tag =>
                <option key={tag.id} value={tag.id === 0 ? '' : tag.id}>{tag.name}</option>)}
            </select>
          </label>
          <label className="RpSettings-field"><FieldLabel label="Заголовок темы" icon="profile.literature"/>
            <input className="FormControl" required maxlength={200} value={data.application.title_template} disabled={disabled}
              oninput={(event: Event) => {data.application.title_template = (event.target as HTMLInputElement).value; state.saved = false;}}/>
          </label>
        </div>
        <p className="RpSettings-note">Тег обязателен; игрокам нужны права на создание тем в нём.</p>
        <label className="RpSettings-field RpSettings-template"><FieldLabel label="Первое сообщение" icon="fields.description"/>
          <textarea className="FormControl" required maxlength={60000} rows={8} value={data.application.body_template} disabled={disabled}
            oninput={(event: Event) => {data.application.body_template = (event.target as HTMLTextAreaElement).value; state.saved = false;}}/>
        </label>
        <div className="RpSettings-placeholders" aria-label="Подстановки в шаблоне">
          <span><code>{'{name}'}</code> Имя и фамилия</span><span><code>{'{age}'}</code> Возраст</span><span><code>{'{race}'}</code> Раса</span>
        </div>
        <div className="RpSettings-process">
          <div><Icon name={uiIcon('review.mechanics')}/><span><strong>Техническая часть</strong><small>Одобрение в профиле</small></span></div>
          <div><Icon name={uiIcon('profile.literature')}/><span><strong>Литературная часть</strong><small>Плашка «Принято» и закрытая тема</small></span></div>
        </div>
        <footer className="RpSettings-footer">
          <small><Icon name={uiIcon('actions.select')}/> Активация после двух одобрений.</small>
          <Button type="submit" className="Button Button--primary" icon={uiIcon('actions.save')} loading={state.saving}
            disabled={disabled || state.stale}>Сохранить изменения</Button>
        </footer>
      </form>}
    </section>;
  }
}
