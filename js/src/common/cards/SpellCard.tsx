import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import Icon from 'flarum/common/components/Icon';
import EntityCardFrame from './EntityCardFrame';
import type {Fields, CatalogRow} from '../catalogData';
import {number, text} from '../catalogData';
import {damage, pathColor, roman, time} from '../presentation';
import {uiIcon} from '../uiIcons';

interface Attrs {mode: 'compact' | 'expanded'; spell: Fields; path?: CatalogRow}

/** Отсутствующие параметры не создают пустых элементов в строке метаданных. */
function renderCard({mode, spell, path}: Attrs): Mithril.Children {
  const distance = text(spell, 'distance_mode') === 'self' ? 'На себя'
    : text(spell, 'distance_mode') === 'touch' ? 'Касание' : number(spell, 'distance_value') + ' м';
  const radius = number(spell, 'radius_value') > 0 ? number(spell, 'radius_value') + ' м' : '';
  const duration = time(number(spell, 'duration_value'), text(spell, 'duration_unit'));
  const cast = text(spell, 'cast_mode') === 'instant' ? 'Мгновенно' : time(number(spell, 'cast_value'), text(spell, 'cast_unit'));
  const cooldown = time(number(spell, 'cooldown_value'), text(spell, 'cooldown_unit'));
  const hit = number(spell, 'damage_units') > 0 ? damage(number(spell, 'damage_units')) : '';
  const details = [distance, radius, duration, cast, cooldown, text(spell, 'description')].filter(Boolean).join('\n');
  return <EntityCardFrame kind="spell" mode={mode} name={text(spell, 'name') + ' ' + roman(number(spell, 'level'))}
    color={pathColor(path ? text(path, 'color') : '')} icon={path ? text(path, 'icon') : undefined}
    iconHint={path ? text(path, 'name') : 'Путь магии не указан'}
    hint={details} description={text(spell, 'description')}
    meta={mode === 'expanded' && <span className="RpCard-meta">
      <span title="Дистанция"><Icon name={uiIcon('fields.distance')}/> {distance}</span>
      {radius && <span title="Радиус"><Icon name={uiIcon('fields.radius')}/> {radius}</span>}
      {duration && <span title="Длительность"><Icon name={uiIcon('fields.duration')}/> {duration}</span>}
    </span>}
    footer={<div className="RpCard-footerContents">
      <div className="RpCard-footerDetails">
        <strong className="RpCard-footerLabel">{hit && <span title={hit}><Icon name={uiIcon('fields.damage')}/> {hit}</span>}</strong>
        {mode === 'expanded' && <span title={'Время накладывания: ' + cast}><Icon name={uiIcon('fields.castTime')}/> {cast}</span>}
        {mode === 'expanded' && cooldown && <span title={'Перезарядка: ' + cooldown}><Icon name={uiIcon('fields.cooldown')}/> {cooldown}</span>}
      </div>
      {/* Затраты закреплены справа независимо от наличия урона и перезарядки. */}
      <div className="RpCard-resources">
        <span className="RpResource RpResource--mana" title="Мана"><Icon name={uiIcon('resources.mana')}/> {number(spell, 'mana_cost')}</span>
        {number(spell, 'stamina_cost') > 0 && <span className="RpResource RpResource--stamina" title="Выносливость">
          <Icon name={uiIcon('resources.stamina')}/> {number(spell, 'stamina_cost')}</span>}
        {text(spell, 'other_cost') && <span title={'Другие затраты: ' + text(spell, 'other_cost')}>{text(spell, 'other_cost')}</span>}
      </div>
    </div>}/>;
}
export default class SpellCard extends Component<Attrs> {
  view() { return renderCard(this.attrs); }
}
