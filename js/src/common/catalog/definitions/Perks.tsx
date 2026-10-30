import {definition, nameColumn} from './base';
import PerkEditor from '../../editors/PerkEditor';
export default definition({
  key: 'perks', title: 'Перки', icon: 'sections.perks', columns: [nameColumn('image')], template: true,
  defaults: {image: '', rarity: 'common', effects: []}, editor: PerkEditor,
});