import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import Feedback from '../../common/components/Feedback';
import showModal from '../../common/showModal';
import CatalogEditModal from '../../common/catalog/components/CatalogEditModal';
import CatalogListState from '../../admin/catalog/CatalogListState';
import type {CatalogRow} from '../../common/catalog/types';
import perks from '../../common/catalog/definitions/Perks';
import spells from '../../common/catalog/definitions/Spells';
import items from '../../common/catalog/definitions/Items';
import PerkCard from '../../common/cards/PerkCard';
import SpellCard from '../../common/cards/SpellCard';
import ItemCard from '../../common/cards/ItemCard';
import WorkspaceCatalogApi, {type WorkspaceCatalogKey} from './WorkspaceCatalogApi';

const definitions = {perks, spells, items};
interface Attrs {catalog: WorkspaceCatalogKey}

/** Карточки профиля и общие редакторы, без копирования разметки сущностей. */
export default class WorkspaceCatalog extends Component<Attrs> {
  list!: CatalogListState;
  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.list = new CatalogListState(new WorkspaceCatalogApi(this.attrs.catalog));
    void this.list.load();
  }
  onremove(): void { this.list.dispose(); }

  edit(row: CatalogRow | null): void {
    const permissions = this.list.data.capabilities;
    if (row ? !permissions?.edit : !permissions?.create) return;
    showModal(CatalogEditModal, {definition: definitions[this.attrs.catalog], row,
      data: this.list.data, api: this.list.api, onSaved: () => this.list.load()});
  }

  card(row: CatalogRow): Mithril.Children {
    const lookups = this.list.data.lookups;
    if (this.attrs.catalog === 'perks') return <PerkCard mode="expanded" perk={row}/>;
    if (this.attrs.catalog === 'spells') return <SpellCard mode="expanded" spell={row}
      path={lookups.paths?.find(path => path.id === Number(row.magic_path_id))}/>;
    return <ItemCard mode="expanded" item={row} type={lookups['item-types']?.find(type => type.id === Number(row.item_type_id))}/>;
  }

  view(): Mithril.Children {
    const list = this.list;
    const definition = definitions[this.attrs.catalog];
    const permissions = list.data.capabilities;
    const busy = list.loading || list.mutating;
    return <section aria-label={definition.title}>
      <div className="RpWorkspace-toolbar">
        <input className="FormControl" type="search" placeholder="Поиск по названию" aria-label={'Поиск: ' + definition.title}
          value={list.search} oninput={(event: Event) => list.setSearch((event.target as HTMLInputElement).value)}/>
        {definition.filters.map(filter => <select key={filter.key} className="FormControl" aria-label={filter.label}
          value={list.filters[filter.key] ?? ''} onchange={(event: Event) => list.setFilter(filter.key, (event.target as HTMLSelectElement).value)}>
          {[<option key="all" value="">{filter.label}</option>,
            ...Object.entries(filter.options(list.data.lookups)).map(([value, label]) => <option key={value} value={value}>{label}</option>)]}
        </select>)}
        <Button className="Button" icon="fas fa-sync-alt" disabled={busy} onclick={() => void list.load()}>Обновить</Button>
        {permissions?.create && <Button className="Button Button--primary" icon="fas fa-plus" disabled={busy} onclick={() => this.edit(null)}>Создать</Button>}
      </div>
      {list.error && <Feedback message={list.error}/>}
      <p className="helpText" role="status">{list.loading ? 'Загрузка…' : definition.title + ' · ' + list.data.total}</p>
      <div className="RpWorkspace-cards" aria-busy={String(list.loading)}>
        {list.data.rows.map(row => <article key={row.id} className="RpWorkspace-entry">
          {this.card(row)}
          <div className="RpWorkspace-actions">
            {!row.enabled && <span className="helpText">Выключено</span>}
            {permissions?.edit && <>
              <Button className="Button Button--link" icon="fas fa-pen" disabled={busy} onclick={() => this.edit(row)}>Изменить</Button>
              <Button className="Button Button--link" disabled={busy}
                onclick={() => void list.mutate(() => list.api.toggle(row.id, list.data.revision, !row.enabled))}>{row.enabled ? 'Выключить' : 'Включить'}</Button>
            </>}
            {permissions?.delete && <Button className="Button Button--link" icon="fas fa-trash" disabled={busy} onclick={() => {
              if (window.confirm('Удалить «' + row.name + '» из справочника?')) void list.mutate(() => list.api.remove(row.id, list.data.revision));
            }}>Удалить</Button>}
          </div>
        </article>)}
      </div>
      {!busy && !list.data.rows.length && !list.error && <p className="helpText">Записи не найдены</p>}
      <div className="RpWorkspace-pagination">
        <Button className="Button" disabled={busy || list.page <= 1} onclick={() => {--list.page; void list.load();}}>Предыдущая</Button>
        <span>Страница {list.page}</span>
        <Button className="Button" disabled={busy || list.page * list.data.per_page >= list.data.total}
          onclick={() => {++list.page; void list.load();}}>Следующая</Button>
      </div>
    </section>;
  }
}
