import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type {CatalogDefinition, CatalogRow, Lookups} from '../../../common/catalog/types';
import {uiIcon} from '../../../common/uiIcons';

interface Attrs {
  definition: CatalogDefinition;
  rows: CatalogRow[];
  lookups: Lookups;
  disabled: boolean;
  edit: (row: CatalogRow) => void;
  remove: (row: CatalogRow) => void;
}

/** Столбцы задаёт раздел. Разметка строк, пустой список и кнопки не копируются. */
export default class CatalogTable extends Component<Attrs> {
  view(): JSX.Element {
    const {definition, rows, lookups, disabled} = this.attrs;
    return <div className="RpCatalog-tableWrap"><table className="RpCatalog-table">
      <thead><tr>{definition.columns.map(column => <th key={column.key} data-column={column.key}>{column.title}</th>)}
        <th data-column="actions"><span className="sr-only">Действия</span></th>
      </tr></thead>
      <tbody>{rows.map(row => <tr key={row.id}>
        {definition.columns.map(column => <td key={column.key} data-column={column.key}>{column.render(row, lookups)}</td>)}
        <td className="RpCatalog-actions" data-column="actions">
          {/* Один набор действий для всех разделов; подсказка и доступное имя заменяют текст кнопки. */}
          <div className="RpCatalog-rowActions">
            <Button className="Button Button--primary Button--icon" icon={uiIcon('actions.edit')} disabled={disabled}
              title="Изменить" aria-label={'Изменить «' + row.name + '»'}
              onclick={() => this.attrs.edit(row)}/>
            {definition.remove && <Button className="Button Button--danger Button--icon" icon={uiIcon('actions.delete')}
              disabled={disabled} title="Удалить" aria-label={'Удалить «' + row.name + '»'}
              onclick={() => this.attrs.remove(row)}/>}
          </div>
        </td>
      </tr>)}</tbody>
    </table>{rows.length === 0 && <p className="RpCatalog-empty">Записи не найдены</p>}</div>;
  }
}