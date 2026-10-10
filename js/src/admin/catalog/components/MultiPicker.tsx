import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type {CatalogRow} from '../../../common/catalog/types';
import {catalogueIcon} from '../../../common/uiIcons';
import {safeImage} from '../../../common/presentation';

interface Attrs {
  rows: CatalogRow[];
  selected: number[];
  blocked?: number[];
  onchange: (ids: number[]) => void;
  icon?: (row: CatalogRow) => string | undefined;
  label?: (row: CatalogRow) => string;
}

/** Поиск и прокрутка для длинных списков. Пустые плашки не создаются. */
export default class MultiPicker extends Component<Attrs> {
  search = '';

  view(): Mithril.Children {
    const rows = this.attrs.rows.filter(row => row.name.toLocaleLowerCase().includes(this.search.toLocaleLowerCase()));
    return <div className="RpMultiPicker">
      <input className="FormControl" type="search" aria-label="Поиск в списке" placeholder="Поиск…"
        value={this.search} oninput={(event: Event) => this.search = (event.target as HTMLInputElement).value}/>
      <div className="RpMultiPicker-options">{rows.map(row => {
        const selected = this.attrs.selected.includes(row.id);
        const blocked = !selected && (this.attrs.blocked ?? []).includes(row.id);
        const image = safeImage(String(row.image ?? ''));
        return <button key={row.id} type="button" className={'RpMultiPicker-option' + (selected ? ' is-selected' : '')}
          disabled={blocked} aria-pressed={String(selected)}
          onclick={() => this.attrs.onchange(selected ? this.attrs.selected.filter(id => id !== row.id) : [...this.attrs.selected, row.id])}>
          {image ? <img src={image} alt=""/> : <Icon name={catalogueIcon(this.attrs.icon?.(row) ?? String(row.icon ?? ''))}/>}
          <span>{this.attrs.label?.(row) ?? row.name}</span>
        </button>;
      })}</div>
      {rows.length === 0 && <p className="helpText">Записи не найдены</p>}
    </div>;
  }
}