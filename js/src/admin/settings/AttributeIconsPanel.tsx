import Component, {type ComponentAttrs} from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import SettingsState from './SettingsState';
import SettingsHeader from './SettingsHeader';
import SettingsFeedback from './SettingsFeedback';
import AttributeIconsApi, {type AttributeIconsView} from './AttributeIconsApi';
import CatalogApi from '../catalog/CatalogApi';
import CatalogListState from '../catalog/CatalogListState';
import CatalogTransferModal from '../catalog/components/CatalogTransferModal';
import attributes from '../catalog/definitions/Attributes';
import showModal from '../../common/showModal';
import {catalogueIcon, uiIcon} from '../../common/uiIcons';

/** Одна строка для всех характеристик и одна атомарная операция сохранения. */
export default class AttributeIconsPanel extends Component {
  settings!: SettingsState<AttributeIconsView>;
  transferList = new CatalogListState(new CatalogApi(attributes.endpoint));
  openingTransfer = false;
  private active = true;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void {
    super.oninit(vnode);
    const api = new AttributeIconsApi();
    this.settings = new SettingsState(() => api.read(), data => api.save(data), () => m.redraw());
    void this.settings.load();
  }
  onremove(): void {this.active = false; this.settings.dispose(); this.transferList.dispose();}

  async transfer(kind: 'import' | 'export'): Promise<void> {
    if (this.openingTransfer) return;
    this.openingTransfer = true;
    try {
      await this.transferList.load();
      if (!this.active) return;
      if (this.transferList.error) {this.settings.error = this.transferList.error; return;}
      showModal(CatalogTransferModal, {kind, definition: attributes, list: this.transferList,
        onImported: () => this.settings.load()});
    } finally {this.openingTransfer = false; m.redraw();}
  }

  view(): Mithril.Children {
    const state = this.settings, disabled = state.loading || state.saving || this.openingTransfer;
    return <section className="RpSettings-panel RpSettings-panel--icons">
      <SettingsHeader title="Иконки характеристик" icon="fields.attribute" disabled={disabled} refresh={() => void state.load()}>
        <Button className="Button Button--icon" icon={uiIcon('actions.import')} title="Импорт JSON" aria-label="Импорт JSON"
          disabled={disabled} onclick={() => void this.transfer('import')}/>
        <Button className="Button Button--icon" icon={uiIcon('actions.export')} title="Экспорт JSON" aria-label="Экспорт JSON"
          disabled={disabled} onclick={() => void this.transfer('export')}/>
      </SettingsHeader>
      <SettingsFeedback state={state}/>
      {state.data && <form onsubmit={(event: Event) => {event.preventDefault(); void state.save();}}>
        <div className="RpAttributeIcons-row">
          {state.data.rows.map(row => <label key={row.id} className="RpSettings-field">
            <span className="RpForm-label"><Icon name={catalogueIcon(row.icon)}/> {row.name}</span>
            <input className="FormControl" value={row.icon ?? ''} maxlength={100} placeholder="fas fa-…" disabled={disabled}
              oninput={(event: Event) => {row.icon = (event.target as HTMLInputElement).value; state.saved = false;}}/>
          </label>)}
          <Button type="submit" className="Button Button--primary" icon={uiIcon('actions.save')}
            loading={state.saving} disabled={disabled || state.stale}>Сохранить</Button>
        </div>
      </form>}
    </section>;
  }
}
