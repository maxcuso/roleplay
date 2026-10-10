import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import Icon from 'flarum/common/components/Icon';
import {catalogueIcon, uiIcon} from '../uiIcons';
import {safeImage} from '../presentation';

export interface CardFrameAttrs {
  mode: 'compact' | 'expanded';
  kind: 'perk' | 'spell' | 'item';
  name: string;
  color: string;
  icon?: string;
  image?: string;
  description: Mithril.Children;
  footer: Mithril.Children;
  hint?: string;
  cardHint?: string;
  iconHint?: string;
  headingHint?: string;
  meta?: Mithril.Children;
  magical?: boolean;
  quantity?: number;
}

/** Общая геометрия карточек. Полоска сохраняет высоту даже при пустом footer. */
function renderCard(attrs: CardFrameAttrs): JSX.Element {
  const image = safeImage(attrs.image ?? '');
  const glyph = image ? <img src={image} alt=""/> : <Icon name={catalogueIcon(attrs.icon)}/>;
  return <article title={attrs.cardHint ?? (attrs.mode === 'compact' ? attrs.hint : undefined)} className={'RpCard RpCard--' + attrs.mode + ' RpCard--' + attrs.kind} style={{'--rp-card-color': attrs.color, '--rp-card-foreground': foreground(attrs.color)}}>
    <div className="RpCard-main">
      <div className="RpCard-iconArea" title={attrs.iconHint ?? attrs.hint}>
        <div className="RpCard-icon">{glyph}
          {attrs.magical && <span className="RpCard-magical" title="Магический предмет"><Icon name={uiIcon('item.magical')}/></span>}
        </div>
      </div>
      <div className="RpCard-body">
        {/* Одна верхняя строка. Название может сокращаться, но полностью доступно при наведении. */}
        <div className="RpCard-header">
          <div className="RpCard-heading" title={attrs.headingHint ?? attrs.name}><strong>{attrs.name || 'Название'}</strong></div>
          {attrs.meta}
          {attrs.quantity !== undefined && <span className="RpCard-quantity">×{attrs.quantity}</span>}
        </div>
        {attrs.mode === 'expanded' && <div className="RpCard-description">{attrs.description}</div>}
      </div>
    </div>
    <div className="RpCard-footer">{attrs.footer}</div>
  </article>;
}
export default class EntityCardFrame extends Component<CardFrameAttrs> {
  view() { return renderCard(this.attrs); }
}

/** Контраст текста полоски выбирается по цвету сущности; значение не сохраняется в справочнике. */
function foreground(color: string): string {
  if (!/^#[0-9a-f]{6}$/i.test(color)) return '#151515';
  const parts = [1, 3, 5].map(start => parseInt(color.slice(start, start + 2), 16) / 255);
  const [r, g, b] = parts.map(value => value <= .04045 ? value / 12.92 : Math.pow((value + .055) / 1.055, 2.4));
  return .2126 * r + .7152 * g + .0722 * b > .179 ? '#151515' : '#ffffff';
}
