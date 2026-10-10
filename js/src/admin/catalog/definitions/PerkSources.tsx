import {definition, nameColumn} from '../../../common/catalog/definitions/base';
import PerkSourceEditor from '../../editors/PerkSourceEditor';
export default definition({
  key: 'sources', title: 'Источники перков', icon: 'sections.perkSources', columns: [nameColumn()],
  defaults: {icon: '', name_source: '', icon_source: '', attribute_icons: {}}, editor: PerkSourceEditor,
});