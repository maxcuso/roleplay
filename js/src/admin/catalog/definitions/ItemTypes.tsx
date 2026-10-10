import {definition, nameColumn} from '../../../common/catalog/definitions/base';
import ItemTypeEditor from '../../editors/ItemTypeEditor';
import {number} from '../../../common/catalog/helpers';
export default definition({
  key: 'item-types', title: 'Типы предметов', icon: 'sections.itemTypes',
  columns: [nameColumn(), {key: 'equipment_limit', title: 'В экипировке', render: row => {
    const value = number(row, 'equipment_limit');
    return value === -1 ? 'Нельзя экипировать' : value === 0 ? 'Без лимита' : String(value);
  }}],
  defaults: {icon: '', equipment_limit: 0}, editor: ItemTypeEditor,
});