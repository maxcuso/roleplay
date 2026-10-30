import {definition, nameColumn} from '../../../common/catalog/definitions/base';
import MagicPathEditor from '../../editors/MagicPathEditor';
export default definition({
  key: 'paths', title: 'Пути магии', icon: 'sections.paths', columns: [nameColumn()],
  defaults: {icon: '', color: '#45698d'}, editor: MagicPathEditor,
});