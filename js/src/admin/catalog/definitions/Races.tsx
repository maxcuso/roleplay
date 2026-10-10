import Icon from 'flarum/common/components/Icon';
import {definition, nameColumn, listIcons, statusFilter} from '../../../common/catalog/definitions/base';
import RaceEditor from '../../editors/RaceEditor';
import {object, text, number} from '../../../common/catalog/helpers';
import {catalogueIcon} from '../../../common/uiIcons';
import type {Fields} from '../../../common/catalog/types';
const rules: Fields = {fixed_bonuses: {}, choice_attributes: [], attribute_bonus: 1, attribute_count: 0,
  fixed_perk_ids: [], perk_choice_ids: [], perk_choice_count: 0, fixed_spell_ids: [],
  spell_choice_ids: [], spell_choice_path_ids: [], spell_choice_count: 0,
  proficiency_choice_mode: 'list', proficiency_choice_ids: [], proficiency_choice_count: 0};
export default definition({
  key: 'races', title: 'Расы', icon: 'sections.races',
  filters: [{key: 'attribute', label: 'Все характеристики',
    options: lookups => Object.fromEntries((lookups.attributes ?? []).map(row => [text(row, 'slug'), row.name]))}, statusFilter],
  columns: [nameColumn('race'),
    {key: 'bonuses', title: 'Бонусы', render: (row, lookups) => {
      const bonuses = object(object(row.rules).fixed_bonuses);
      const attributes = (lookups.attributes ?? []).filter(attribute => Number(bonuses[text(attribute, 'slug')]));
      return <span className="RpCatalog-icons">{attributes.length ? attributes.map(attribute => {
        const bonus = Number(bonuses[text(attribute, 'slug')]);
        return <span key={attribute.id} title={attribute.name + ': ' + (bonus > 0 ? '+' : '') + bonus}>
          <Icon name={catalogueIcon(text(attribute, 'icon'))}/></span>;
      }) : '—'}</span>;
    }},
    {key: 'choice', title: 'Выбор', render: (row, lookups) => {
      const selected = object(row.rules).choice_attributes;
      return <span className="RpCatalog-icons">{Array.isArray(selected) && selected.length ?
        (lookups.attributes ?? []).filter(attribute => selected.includes(text(attribute, 'slug'))).map(attribute =>
          <span key={attribute.id} title={attribute.name + ': +' + number(object(row.rules), 'attribute_bonus')}>
            <Icon name={catalogueIcon(text(attribute, 'icon'))}/></span>) : '—'}</span>;
    }},
    {key: 'count', title: 'Количество выборов', render: row => number(object(row.rules), 'attribute_count') || '—'},
    {key: 'perks', title: 'Перки', render: (row, refs) => listIcons(refs, 'perks', object(row.rules).fixed_perk_ids)},
    {key: 'choices', title: 'Перки на выбор', render: (row, refs) => listIcons(refs, 'perks', object(row.rules).perk_choice_ids)},
  ],
  defaults: {min_age: null, max_age: null, rules_url: '', rules}, editor: RaceEditor,
});