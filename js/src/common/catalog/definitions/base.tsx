import Icon from 'flarum/common/components/Icon';
import type {CatalogColumn, CatalogDefinition, CatalogFilter, CatalogRow, Fields, Lookups} from '../types';
import {catalogueIcon, uiIcon} from '../../uiIcons';
import {initialFields, lookup, text} from '../helpers';
import {safeImage, pathColor} from '../../presentation';

export const statusFilter: CatalogFilter = {
  key: 'enabled', label: 'Все статусы', options: () => ({'1': 'Включённые', '0': 'Выключенные'}),
};
export function referenceFilter(key: string, label: string, catalogue: string): CatalogFilter {
  return {key, label, options: lookups => Object.fromEntries((lookups[catalogue] ?? []).map(row => [String(row.id), row.name]))};
}
/** У каждого раздела свой небольшой файл. Общие возможности задаются здесь один раз. */
export function definition(
  value: Pick<CatalogDefinition, 'key' | 'title' | 'icon' | 'columns' | 'editor'> &
    Partial<CatalogDefinition> & {defaults?: Fields},
): CatalogDefinition {
  return {
    endpoint: 'catalogs/' + value.key, filters: [statusFilter], create: true, toggle: true, remove: true,
    revision: (_row, result) => result.revision,
    initial: row => initialFields(row, value.defaults ?? {}),
    ...value,
  };
}
export function nameColumn(kind: 'icon' | 'image' | 'race' | 'spell' | 'item' | 'attribute' = 'icon'): CatalogColumn {
  return {key: 'name', title: 'Название', render: (row, lookups) => {
    const reference = kind === 'spell' ? lookup(lookups, 'paths', row.magic_path_id) :
      kind === 'item' ? lookup(lookups, 'item-types', row.item_type_id) :
      kind === 'attribute' ? lookup(lookups, 'attributes', row.attribute_id) : row;
    const image = kind === 'image' ? safeImage(text(row, 'image')) : '';
    return <span className="RpCatalog-name">
      {image ? <img src={image} alt=""/> :
        <Icon name={kind === 'race' ? uiIcon('sections.races') : catalogueIcon(text(reference ?? {}, 'icon'))}
          style={{color: kind === 'spell' || (kind === 'icon' && row.color) ? pathColor(text(reference ?? {}, 'color')) : undefined}}/>}
      <strong className="RpCatalog-nameText" title={row.name}>{row.name}</strong>
    </span>;
  }};
}
export function plainColumn(key: string, title: string): CatalogColumn {
  return {key, title, render: row => key === 'name'
    ? <span className="RpCatalog-nameText" title={text(row, key)}>{text(row, key) || '—'}</span>
    : text(row, key) || '—'};
}
export function listIcons(lookups: Lookups, catalogue: string, value: unknown): JSX.Element {
  const selected = Array.isArray(value) ? value.map(Number) : [];
  return <span className="RpCatalog-icons">{selected.length ? selected.map(id => {
    const row = lookup(lookups, catalogue, id);
    if (!row) return null;
    const image = safeImage(text(row, 'image'));
    return <span key={id} title={row.name}>{image ? <img src={image} alt=""/> :
      <Icon name={catalogueIcon(text(row, 'icon'))}/>}</span>;
  }) : '—'}</span>;
}