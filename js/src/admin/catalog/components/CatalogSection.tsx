import showModal from './showModal';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import CatalogApi from '../CatalogApi';
import CatalogListState from '../CatalogListState';
import CatalogTable from './CatalogTable';
import CatalogToolbar from './CatalogToolbar';
import CatalogStatusSwitch from './CatalogStatusSwitch';
import CatalogEditModal from '../../../common/catalog/components/CatalogEditModal';
import CatalogTemplateModal from './CatalogTemplateModal';
import CatalogTransferModal from './CatalogTransferModal';
import type {CatalogDefinition, CatalogRow} from '../../../common/catalog/types';

interface Attrs {definition: CatalogDefinition}

export default class CatalogSection extends Component<Attrs> {
  list!: CatalogListState;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.list = new CatalogListState(new CatalogApi(this.attrs.definition.endpoint));
    void this.list.load();
  }

  onremove(): void { this.list.dispose(); }

  edit(row: CatalogRow | null, template = false): void {
    const definition = this.attrs.definition;
    const copy = template && row ? {...row, name: 'Копия: ' + row.name} : row;
    showModal(CatalogEditModal, {
      definition: template ? {...definition, initial: () => definition.initial(copy)} : definition,
      row: template ? null : row, data: this.list.data, api: this.list.api,
      onSaved: async () => { await this.list.load(); },
    });
  }

  template(): void {
    showModal(CatalogTemplateModal, {
      rows: this.list.data.lookups.perks ?? [],
      select: (row: CatalogRow) => this.edit(row, true),
    });
  }

  view(): JSX.Element {
    const definition = this.attrs.definition;
    const list = this.list;
    const disabled = list.loading || list.mutating;
    const columns = [...definition.columns];
    // Статус включается описанием раздела, а не копированием таблицы.
    if (definition.toggle) columns.splice(1, 0, {
      key: 'enabled', title: 'Статус',
      render: row => <CatalogStatusSwitch enabled={Boolean(row.enabled)} label={'Доступность: ' + row.name}
        disabled={disabled} onchange={() => void list.mutate(() =>
          list.api.toggle(row.id, list.data.revision, !row.enabled))}/>,
    });
    return <div className="RpCatalog">
      <CatalogToolbar definition={definition} list={list} create={() => this.edit(null)} template={() => this.template()}
        transfer={(kind: 'import' | 'export') => showModal(CatalogTransferModal, {kind, definition, list})}/>
      <p className="RpCatalog-count">{definition.title} · {list.data.total}</p>
      {list.error && <div className="RpCatalog-error" role="alert">{list.error}</div>}
      <div aria-busy={String(list.loading)}>
        <CatalogTable definition={{...definition, columns}} rows={list.data.rows} lookups={list.data.lookups}
          disabled={disabled} edit={(row: CatalogRow) => this.edit(row)} remove={(row: CatalogRow) => {
            if (window.confirm(definition.removeConfirmation?.(row) ?? ('Удалить «' + row.name + '»?'))) {
              void list.mutate(() => list.api.remove(row.id, definition.revision(row, list.data)));
            }
          }}/>
      </div>
      <div className="RpCatalog-pagination">
        <Button className="Button" disabled={disabled || list.page <= 1}
          onclick={() => {--list.page; void list.load();}}>Предыдущая</Button>
        <span>Страница {list.page}</span>
        <Button className="Button" disabled={disabled || list.page * list.data.per_page >= list.data.total}
          onclick={() => {++list.page; void list.load();}}>Следующая</Button>
      </div>
    </div>;
  }
}
