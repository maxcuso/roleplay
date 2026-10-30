import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type {CatalogDefinition} from '../../../common/catalog/types';
import CatalogListState from '../CatalogListState';
import {uiIcon} from '../../../common/uiIcons';

interface Attrs {
  definition: CatalogDefinition; list: CatalogListState; create: () => void; template: () => void;
  transfer: (kind: 'import' | 'export') => void;
}

export default class CatalogToolbar extends Component<Attrs> {
  view(): JSX.Element {
    const {definition, list} = this.attrs;
    return <div className="RpCatalog-toolbar">
      <input className="FormControl RpCatalog-search" type="search" maxlength={100}
        aria-label="Поиск по названию" placeholder="Поиск по названию…" value={list.search}
        disabled={list.mutating} oninput={(event: Event) => list.setSearch((event.target as HTMLInputElement).value)}/>
      {definition.filters.map(filter => <select key={filter.key} className="FormControl"
        aria-label={filter.label} value={list.filters[filter.key] ?? ''} disabled={list.mutating}
        onchange={(event: Event) => list.setFilter(filter.key, (event.target as HTMLSelectElement).value)}>
        {Object.entries({'': filter.label, ...filter.options(list.data.lookups)}).map(([value, label]) =>
          <option key={value || 'all'} value={value}>{label}</option>)}
      </select>)}
      <div className="RpCatalog-toolbarActions">
        {definition.key !== 'characters' && <span className="RpCatalog-transferActions">
          <Button className="Button Button--icon" icon={uiIcon('actions.import')} title="Импорт JSON" aria-label="Импорт JSON"
            disabled={list.loading || list.mutating || list.data.revision < 1} onclick={() => this.attrs.transfer('import')}/>
          <Button className="Button Button--icon" icon={uiIcon('actions.export')} title="Экспорт JSON" aria-label="Экспорт JSON"
            disabled={list.loading || list.mutating} onclick={() => this.attrs.transfer('export')}/>
        </span>}
        <Button className="Button Button--icon" icon={uiIcon('actions.refresh')} aria-label="Обновить список"
          disabled={list.loading || list.mutating} onclick={() => void list.load()}/>
        {definition.create && <Button className="Button Button--primary" icon={uiIcon('actions.create')}
          disabled={list.loading || list.mutating || list.data.revision < 1} onclick={this.attrs.create}>Создать</Button>}
        {definition.template && <Button className="Button Button--primary"
          disabled={list.loading || list.mutating || !(list.data.lookups.perks ?? []).length}
          onclick={this.attrs.template}>Создать из шаблона</Button>}
      </div>
    </div>;
  }
}
