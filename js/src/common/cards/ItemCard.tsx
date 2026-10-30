import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import Icon from 'flarum/common/components/Icon';
import EntityCardFrame from './EntityCardFrame';
import type {Fields, CatalogRow} from '../catalogData';
import {number, text} from '../catalogData';
import {damage, rarityColor, time, itemStackPrice} from '../presentation';
import {uiIcon} from '../uiIcons';

export interface ItemProperty {description: string; cooldown_value: number; cooldown_unit: string}
interface Attrs {mode: 'compact' | 'expanded'; item: Fields; type?: CatalogRow; quantity?: number}

function renderCard({mode, item, type, quantity = 1}: Attrs): Mithril.Children {
  const properties = Array.isArray(item.properties) ? item.properties as ItemProperty[] : [];
  const damageText = number(item, 'damage_units') > 0 ? damage(number(item, 'damage_units')) : '';
  // Маленькая карточка объясняет действие предмета, а не его внешний вид.
  // Подсказка доступна на всей карточке, включая сокращённое название.
  const propertyText = properties.map(property => property.description
    + (property.cooldown_value > 0 ? '\nПерезарядка: ' + time(property.cooldown_value, property.cooldown_unit) : ''))
    .filter(Boolean).join('\n');
  const tooltip = text(item, 'name') + '\n' + (propertyText || 'Свойства отсутствуют');
  return <EntityCardFrame kind="item" mode={mode} name={text(item, 'name')} color={rarityColor(text(item, 'rarity'))}
    headingHint={mode === 'compact' ? tooltip : undefined}
    icon={type ? text(type, 'icon') : undefined} hint={mode === 'compact' ? tooltip : text(item, 'appearance')}
    magical={Boolean(item.is_magical)} quantity={mode === 'expanded' ? quantity : undefined}
    description={<div>{properties.map((property, index) => <div key={index} className="RpCard-property">
      <span>{property.description}</span>
      {property.cooldown_value > 0 && <small><Icon name={uiIcon('fields.cooldown')}/> {time(property.cooldown_value, property.cooldown_unit)}</small>}
    </div>)}</div>}
    footer={<div className="RpCard-footerContents">
      <strong className="RpCard-footerLabel">{damageText && <span title={damageText}><Icon name={uiIcon('fields.damage')}/> {damageText}</span>}</strong>
      <div className="RpCard-resources">
        <span className="RpResource RpResource--weight" title="Вес"><Icon name={uiIcon('resources.weight')}/> {number(item, 'weight')}</span>
        <span className="RpResource RpResource--gold" title={quantity > 1 ? 'Стоимость стопки' : 'Стоимость'}><Icon name={uiIcon('resources.gold')}/> {itemStackPrice(number(item, 'base_price'), quantity)}</span>
      </div>
    </div>}/>;
}
export default class ItemCard extends Component<Attrs> {
  view() { return renderCard(this.attrs); }
}
