import {definition, nameColumn, referenceFilter, statusFilter} from '../../../common/catalog/definitions/base';
import ProficiencyEditor from '../../editors/ProficiencyEditor';
import {lookup} from '../../../common/catalog/helpers';

export default definition({
  key: 'proficiencies', title: 'Владения', icon: 'sections.training',
  columns: [
    nameColumn('attribute'),
    {key: 'attribute', title: 'Характеристика', render: (row, lookups) =>
      lookup(lookups, 'attributes', row.attribute_id)?.name ?? '—'},
  ],
  filters: [referenceFilter('attribute', 'Все характеристики', 'attributes'), statusFilter],
  defaults: {attribute_id: null}, editor: ProficiencyEditor,
});