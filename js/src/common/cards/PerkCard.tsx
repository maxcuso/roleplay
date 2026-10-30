import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import Icon from 'flarum/common/components/Icon';
import EntityCardFrame from './EntityCardFrame';
import type {Fields} from '../catalogData';
import {text} from '../catalogData';
import {catalogueIcon} from '../uiIcons';
import {rarityColor, rarityNeuterName} from '../presentation';

interface Attrs {mode: 'compact' | 'expanded'; perk: Fields; source?: {name: string; icon?: string}}

/** Источник передаётся из конкретной выдачи. Редактор определения не выдумывает источник. */
function renderCard({mode, perk, source}: Attrs): Mithril.Children {
  const fullHint = mode === 'expanded' ? text(perk, 'name') + '\n' + rarityNeuterName(text(perk, 'rarity')) : undefined;
  return <EntityCardFrame kind="perk" mode={mode} name={text(perk, 'name')} color={rarityColor(text(perk, 'rarity'))}
    image={text(perk, 'image')} description={text(perk, 'description')} hint={fullHint ?? text(perk, 'description')}
    cardHint={fullHint} headingHint={fullHint}
    footer={source && <strong className="RpCard-footerLabel"><Icon name={catalogueIcon(source.icon)}/> {source.name}</strong>}/>;
}
export default class PerkCard extends Component<Attrs> {
  view() { return renderCard(this.attrs); }
}
