import Icon from 'flarum/common/components/Icon';
import {definition, nameColumn, referenceFilter, statusFilter} from './base';
import SpellEditor from '../../editors/SpellEditor';
import {text, number} from '../helpers';
import {damage, roman, time} from '../../presentation';
import {uiIcon} from '../../uiIcons';
export default definition({
  key: 'spells', title: 'Заклинания', icon: 'sections.spells',
  filters: [referenceFilter('path', 'Все пути', 'paths'), {
    key: 'level', label: 'Все круги',
    options: lookups => Object.fromEntries([...new Set((lookups.spells ?? []).map(row => number(row, 'level')))]
      .sort((a, b) => a - b).map(value => [String(value), roman(value)])),
  }, statusFilter],
  columns: [
    nameColumn('spell'), {key: 'level', title: 'Круг', render: row => roman(number(row, 'level'))},
    {key: 'distance', title: 'Дистанция', render: row => text(row, 'distance_mode') === 'self' ? 'На себя' :
      text(row, 'distance_mode') === 'touch' ? 'Касание' : number(row, 'distance_value') + ' м'},
    {key: 'radius', title: 'Радиус', render: row => number(row, 'radius_value') ? number(row, 'radius_value') + ' м' : '—'},
    {key: 'duration', title: 'Длительность', render: row => time(number(row, 'duration_value'), text(row, 'duration_unit')) || '—'},
    {key: 'damage', title: 'Урон', render: row => number(row, 'damage_units') ? damage(number(row, 'damage_units')) : '—'},
    {key: 'cast', title: 'Время накладывания', render: row => text(row, 'cast_mode') === 'instant' ? 'Мгновенно' :
      time(number(row, 'cast_value'), text(row, 'cast_unit'))},
    {key: 'cooldown', title: 'Перезарядка', render: row => time(number(row, 'cooldown_value'), text(row, 'cooldown_unit')) || '—'},
    {key: 'costs', title: 'Затраты', render: row => <span className="RpCatalog-costs">
      <span className="RpMana" title="Мана"><Icon name={uiIcon('resources.mana')}/> {number(row, 'mana_cost')}</span>
      {number(row, 'stamina_cost') > 0 && <span className="RpStamina" title="Выносливость">
        <Icon name={uiIcon('resources.stamina')}/> {number(row, 'stamina_cost')}</span>}
      {text(row, 'other_cost') && <span title={text(row, 'other_cost')}>{text(row, 'other_cost')}</span>}
    </span>},
  ],
  defaults: {magic_path_id: null, level: 1, is_starting: true, distance_mode: 'self', distance_value: null, radius_value: 0,
    duration_value: 0, duration_unit: 'turn', damage_units: 0, cast_mode: 'instant', cast_value: null,
    cast_unit: 'turn', cooldown_value: 0, cooldown_unit: 'turn', mana_cost: 0, stamina_cost: 0, other_cost: ''},
  editor: SpellEditor,
});
