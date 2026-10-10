import Icon from 'flarum/common/components/Icon';
import {definition, nameColumn, referenceFilter, statusFilter} from './base';
import ItemEditor from '../../editors/ItemEditor';
import {number, object, text} from '../helpers';
import {damage, itemStackPrice} from '../../presentation';
import {uiIcon} from '../../uiIcons';
export default definition({
  key: 'items', title: 'Предметы', icon: 'sections.items',
  filters: [referenceFilter('type', 'Все типы', 'item-types'),
    {key: 'magical', label: 'Все предметы', options: () => ({'1': 'Только магические', '0': 'Немагические'})}, statusFilter],
  columns: [
    nameColumn('item'),
    {key: 'magical', title: 'Магический', render: row =>
      <input type="checkbox" checked={Boolean(row.is_magical)} disabled aria-label="Магический предмет"/>},
    {key: 'properties', title: 'Свойства', render: row => {
      const properties = Array.isArray(row.properties) ? row.properties : [];
      return <span title={properties.map(value => text(object(value), 'description')).join('\n')}>
        {properties.length || '—'}</span>;
    }},
    {key: 'damage', title: 'Урон', render: row => number(row, 'damage_units') ? damage(number(row, 'damage_units')) : '—'},
    {key: 'price', title: 'Стоимость', render: row => <span className="RpGold">
      <Icon name={uiIcon('resources.gold')}/> {itemStackPrice(number(row, 'base_price'))}</span>},
    {key: 'weight', title: 'Вес', render: row => <span><Icon name={uiIcon('resources.weight')}/> {number(row, 'weight')}</span>},
  ],
  defaults: {item_type_id: null, rarity: 'common', appearance: '', is_magical: false,
    is_melee_weapon: false, is_ranged_weapon: false, is_magic_weapon: false, is_armor: false,
    is_ingredient: false, is_literature: false,
    damage_units: 0, ignored_damage_units: 0,
    melee_proficiency_id: null, ranged_proficiency_id: null, magic_proficiency_id: null,
    weight: 0, base_price: 0, max_stack: 1, properties: [], effects: []}, editor: ItemEditor,
});
