import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type {CatalogRow} from '../../common/catalogData';
import type {LoadoutGroup, LoadoutOption} from './CreationSettingsApi';
import {uiIcon} from '../../common/uiIcons';

interface Attrs {groups: LoadoutGroup[]; items: CatalogRow[]; disabled: boolean; changed: () => void}
/** Группа даёт автоматический набор либо выбор вариантов. Стабильные key сохраняются при переименовании. */
export default class LoadoutEditor extends Component<Attrs> {
  search = '';

  private newOption(): LoadoutOption {return {key: 'option-' + crypto.randomUUID(), name: 'Новый набор', items: []};}

  view(): Mithril.Children {
    const {groups, disabled, changed} = this.attrs;
    return <div className="RpLoadout-editor">
      <div className="RpLoadout-toolbar">
        <label className="RpLoadout-search"><Icon name={uiIcon('actions.search')}/>
          <input className="FormControl" aria-label="Поиск предметов для добавления" placeholder="Поиск предметов…"
            value={this.search} disabled={disabled} oninput={(event: Event) => {this.search = (event.target as HTMLInputElement).value;}}/>
        </label>
        <Button type="button" className="Button Button--link" icon={uiIcon('actions.create')} disabled={disabled} onclick={() => {
          groups.push({key: 'group-' + crypto.randomUUID(), name: 'Новая группа', automatic: false, min: 1, max: 1, options: [this.newOption()]});
          changed();
        }}>Добавить группу</Button>
      </div>
      <div>{groups.map((group, index) => <fieldset key={group.key} className="RpLoadout-group" disabled={disabled}>
        <legend className="sr-only">{group.name}</legend>
        <div className="RpLoadout-groupHeader">
          <label className="RpLoadout-name"><Icon name={uiIcon('fields.stack')}/>
            <input className="FormControl" aria-label="Название группы" value={group.name} required maxlength={100}
              oninput={(event: Event) => {group.name = (event.target as HTMLInputElement).value; changed();}}/>
          </label>
          <label className="RpSettings-toggle"><input type="checkbox" checked={group.automatic} disabled={disabled} onchange={(event: Event) => {
            group.automatic = (event.target as HTMLInputElement).checked;
            if (group.automatic) {group.min = 1; group.max = 1;}
            changed();
          }}/> Выдать автоматически</label>
          <div className="RpLoadout-limit"><span>Выбрать</span>
            <input className="FormControl" type="number" aria-label="Минимум выбранных вариантов" required min={0} max={100}
              value={group.min} disabled={disabled || group.automatic} oninput={(event: Event) => {
                group.min = Number((event.target as HTMLInputElement).value); changed();
              }}/><span>—</span>
            <input className="FormControl" type="number" aria-label="Максимум выбранных вариантов" required min={0} max={100}
              value={group.max} disabled={disabled || group.automatic} oninput={(event: Event) => {
                group.max = Number((event.target as HTMLInputElement).value); changed();
              }}/>
          </div>
          <Button type="button" className="Button Button--icon" icon={uiIcon('actions.delete')} title="Удалить группу" aria-label="Удалить группу"
            disabled={disabled} onclick={() => {groups.splice(index, 1); changed();}}/>
        </div>
        <div>{group.options.map((option, optionIndex) => <div key={option.key} className="RpLoadout-option">
          <div className="RpLoadout-optionHeader">
            <label className="RpLoadout-name"><Icon name={uiIcon('sections.inventory')}/>
              <input className="FormControl" aria-label="Название набора" value={option.name} required maxlength={100}
                oninput={(event: Event) => {option.name = (event.target as HTMLInputElement).value; changed();}}/>
            </label>
            <Button type="button" className="Button Button--icon" icon={uiIcon('actions.delete')} title="Удалить набор" aria-label="Удалить набор"
              disabled={disabled} onclick={() => {group.options.splice(optionIndex, 1); changed();}}/>
          </div>
          <div>{option.items.map((entry, itemIndex) => this.itemRow(option, itemIndex))}</div>
          <Button type="button" className="Button Button--link" icon={uiIcon('actions.create')} disabled={disabled}
            onclick={() => {option.items.push({item_id: 0, quantity: 1}); changed();}}>Добавить предмет</Button>
        </div>)}</div>
        <Button type="button" className="Button Button--link RpLoadout-addOption" icon={uiIcon('actions.create')} disabled={disabled}
          onclick={() => {group.options.push(this.newOption()); changed();}}>Добавить набор</Button>
      </fieldset>)}</div>
      {!groups.length && <p className="RpSettings-note">Стартовые наборы пока не заданы.</p>}
    </div>;
  }

  /** Выбранный предмет остаётся в списке даже при смене поисковой строки. */
  private itemRow(option: LoadoutOption, itemIndex: number): Mithril.Children {
    const {items, disabled, changed} = this.attrs;
    const entry = option.items[itemIndex];
    return <div key={itemIndex} className="RpLoadout-item">
      <select className="FormControl" aria-label="Предмет в наборе" disabled={disabled} value={entry.item_id} onchange={(event: Event) => {
        entry.item_id = Number((event.target as HTMLSelectElement).value); changed();
      }}>
        {[{id: 0, name: 'Выбери предмет', enabled: true}, ...items.filter(item =>
          item.id === entry.item_id || item.name.toLocaleLowerCase('ru').includes(this.search.toLocaleLowerCase('ru')))].map(item =>
            <option key={item.id} value={item.id}>{item.name}{item.enabled === false ? ' (выключен)' : ''}</option>)}
      </select>
      <span className="RpLoadout-quantity"><span aria-hidden="true">×</span>
        <input className="FormControl" type="number" aria-label="Количество" required min={1} max={1000000}
          value={entry.quantity} disabled={disabled} oninput={(event: Event) => {
            entry.quantity = Number((event.target as HTMLInputElement).value); changed();
          }}/>
      </span>
      <Button type="button" className="Button Button--icon" icon={uiIcon('actions.close')} title="Убрать предмет" aria-label="Убрать предмет"
        disabled={disabled} onclick={() => {option.items.splice(itemIndex, 1); changed();}}/>
    </div>;
  }
}
