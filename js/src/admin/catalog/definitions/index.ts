import races from './Races';
import paths from './MagicPaths';
import itemTypes from './ItemTypes';
import sources from './PerkSources';
import spells from '../../../common/catalog/definitions/Spells';
import items from '../../../common/catalog/definitions/Items';
import perks from '../../../common/catalog/definitions/Perks';
import characters from './Characters';
import attributes from './Attributes';
import skills from './Skills';
import proficiencies from './Proficiencies';
import ranks from './Ranks';
import type {CatalogDefinition} from '../../../common/catalog/types';
import type {UiIconName} from '../../../common/uiIcons';

export interface AdminSection {
  key: string;
  title: string;
  icon: UiIconName;
  catalogue?: CatalogDefinition;
  children?: AdminSection[];
}
/** Реестр описывает навигацию. Редакторы и API подключаются через описания каталогов. */
export const sections: AdminSection[] = [
  races, paths, itemTypes, sources,
  spells, items, perks, ranks, characters,
].map(value => 'endpoint' in value ? {...value, catalogue: value as CatalogDefinition} : value as AdminSection);
/** Группировка находится только здесь. Редакторы ничего не знают о вкладках и вложенном меню. */
sections.splice(4, 0, {
  key: 'training', title: 'Навыки и владения', icon: 'sections.training',
  children: [skills, proficiencies].map(value => ({...value, catalogue: value})),
});
export const attributeSettings: AdminSection = {...attributes, catalogue: attributes};
