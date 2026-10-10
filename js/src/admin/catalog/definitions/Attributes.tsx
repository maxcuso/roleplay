import {definition, nameColumn} from '../../../common/catalog/definitions/base';
import AttributeEditor from '../../editors/AttributeEditor';
export default definition({
  key: 'attributes', title: 'Иконки характеристик', icon: 'sections.settings', columns: [nameColumn()],
  create: false, toggle: false, remove: false, filters: [], defaults: {icon: ''}, editor: AttributeEditor,
});