import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type {Fields, Lookups, CatalogRow} from '../catalogData';
import {object, text, lookup} from '../catalogData';
import {sourceLabel} from '../perkSource';
import {catalogueIcon, uiIcon} from '../uiIcons';
import {damage, roman} from '../presentation';
import PerkCard from '../cards/PerkCard';
import SpellCard from '../cards/SpellCard';
import ItemCard from '../cards/ItemCard';
import SelectableEntityCard from '../cards/SelectableEntityCard';
import {characterStatus} from './characterStatus';
import RankLabel from './RankLabel';

interface Attrs {
  review: {snapshot: Fields; calculation: Fields};
  lookups: Lookups;
  statusLabel?: string;
  attributeControl?: (id: number) => {allowed: boolean; selected: boolean; choose: () => void};
  attributeActions?: Mithril.Children;
  trainingContent?: Mithril.Children;
  itemControl?: (row: Fields) => {disabled: boolean; toggle: () => void; hint?: string} | undefined;
}
const rows = (value: unknown): Fields[] => Array.isArray(value) ? value as Fields[] : [];

/** Общая анкета только отображает готовое состояние. Редактирование и расчёты остаются вне неё. */
export default class CharacterSheet extends Component<Attrs> {
  view(): Mithril.Children {
    const {snapshot, calculation} = this.attrs.review;
    const lookups = this.attrs.lookups, character = object(snapshot.character);
    const attributes = object(calculation.effective_attributes), balances = object(snapshot.balances);
    const stats = object(calculation.stats);
    const value = (key: string): number => Number(object(stats[key]).value ?? 0);
    // Итог атаки уже включает базу, оружие и применимые эффекты: повторно базу не прибавляем.
    // Без доступного оружия/заклинания показываем чистый вклад характеристики, а не «Нет».
    const damageValue = (key: string): number => Number(
      object(object(object(calculation.maximum_damage)[key]).damage).value
      ?? object(object(calculation.base_damage)[key]).value ?? 0);
    const inventory = rows(snapshot.inventory), equipment = rows(snapshot.equipment);
    const skills = rows(snapshot.skills), proficiencies = rows(snapshot.proficiencies);
    const section = (title: string, caption: string, icon: Parameters<typeof uiIcon>[0], content: Mithril.Children,
      extra?: Mithril.Children): JSX.Element => <section className={'RpSheet-section RpSheet-section--' + icon.split('.')[1]}>
        <header><span className="RpSheet-sectionIcon"><Icon name={uiIcon(icon)}/></span>
          <div><strong>{title}</strong><small>{caption}</small></div>{extra}</header>{content}
      </section>;
    const item = (row: Fields, compact = false): Mithril.Children => {
      const control = this.attrs.itemControl?.(row);
      const equipped = Boolean(row.equipped);
      // Две карточки одного экземпляра управляют одним состоянием: в инвентаре
      // можно надеть/снять предмет, в экипировке клик снимает его.
      // В экипировке показываем только действие снятия, без повторной отметки выбора.
      return <div className="RpSheet-item" key={Number(row.id)}>
        <SelectableEntityCard name={(equipped ? 'Снять: ' : 'Экипировать: ') + text(row, 'name')}
          selected={equipped} showSelection={!compact} readonly={!control} disabled={control?.disabled ?? false}
          hint={control?.hint} onchange={() => control?.toggle()}>
          <ItemCard mode={compact ? 'compact' : 'expanded'} item={object(row.definition)}
            type={object(row.type) as CatalogRow} quantity={Number(row.quantity)}/>
        </SelectableEntityCard>
      </div>;
    };
    return <article className="RpSheet">
      <div className="RpSheet-summary">
        <div className="RpSheet-identity">
          <strong>{text(character, 'first_name')} {text(character, 'last_name')}</strong>
          <span className={'RpSheet-status is-' + (this.attrs.statusLabel === 'Требуются исправления' ? 'changes_requested' : text(character, 'status'))}>• {(this.attrs.statusLabel ?? characterStatus(text(character, 'status'))).toLocaleUpperCase('ru')}</span>
          <p>{text(object(character.race), 'name')} • {text(character, 'age')}
            {character.rank ? <> • <RankLabel rank={object(character.rank)}/></> : ''}</p>
          <div className="RpSheet-resources">
            <span className="RpMana"><Icon name={uiIcon('resources.mana')}/> {value('mana_max')}</span>
            <span className="RpStamina"><Icon name={uiIcon('resources.stamina')}/> {value('stamina_max')}</span>
            <span><Icon name={uiIcon('resources.lifetimeExperience')}/> {Number(balances.experience_total ?? 0)}</span>
          </div>
        </div>
        <div className="RpSheet-numbers">
          <div className="RpSheet-attributes">{Object.entries(attributes).map(([slug, raw]) => {
            const row = object(raw);
            const acquired = object(object(calculation.acquired_attributes)[slug]);
            const details = rows(row.sources).map(part => text(object(part.source), 'label') + ': ' + Number(part.value));
            const hint = text(row, 'name') + '\nПриобретено: ' + Number(acquired.level ?? row.level)
              + '\nИтог: ' + Number(row.level) + '\n' + details.join('\n');
            const control = this.attrs.attributeControl?.(Number(row.id));
            return <div key={slug} title={hint} className={(control?.allowed ? 'is-available ' : '') + (control?.selected ? 'is-selected' : '')}
              role={control?.allowed ? 'button' : undefined} tabindex={control?.allowed ? 0 : undefined}
              aria-pressed={control?.allowed ? control.selected : undefined} onclick={control?.allowed ? control.choose : undefined}
              onkeydown={control?.allowed ? (event: KeyboardEvent) => {
                if (event.key === 'Enter' || event.key === ' ') {event.preventDefault(); control.choose();}
              } : undefined}>
              {control?.allowed && <Icon className="RpDevelopment-arrow" name={uiIcon('actions.upgrade')}/>}
              <Icon name={catalogueIcon(text(lookup(lookups, 'attributes', row.id) ?? {}, 'icon'))}/>
              <strong>{Number(row.level)}</strong>
            </div>;
          })}</div>
          <div className="RpSheet-balances">
            <span><Icon name={uiIcon('resources.activeExperience')}/> {Number(balances.experience_active ?? 0)}</span>
            <span className="RpGold"><Icon name={uiIcon('resources.gold')}/> {Number(balances.gold ?? 0)}</span>
          </div>
          {this.attrs.attributeActions}
        </div>
      </div>
      <div className="RpSheet-combat">{[
        ['combat.melee', 'Урон в ближнем бою', damageValue('melee_damage')],
        ['combat.ranged', 'Урон в дальнем бою / метательным оружием', damageValue('ranged_damage')],
        ['combat.magic', 'Магический урон', damageValue('magic_damage')],
        ['combat.defense', 'Игнорируемый урон', value('ignored_damage')],
      ].map(([icon, title, amount]) => <span key={String(icon)} title={String(title)}>
        <span className="RpSheet-combatValue"><Icon name={uiIcon(icon as Parameters<typeof uiIcon>[0])}/> {damage(Number(amount))}</span>
      </span>)}</div>
      {section('Навыки и владения', 'Что умеет персонаж и каким оружием владеет', 'sections.training',
        this.attrs.trainingContent ?? <div className="RpSheet-training">{lookups.attributes.map(attribute => <div key={attribute.id}>
          <Icon name={catalogueIcon(text(attribute, 'icon'))}/>
          {skills.filter(row => object(row.definition).attribute_id === attribute.id).map(row =>
            <span className="is-learned" key={'skill:' + row.id}>{text(row, 'name')} {roman(Number(row.level))}</span>)}
          {proficiencies.filter(row => object(row.definition).attribute_id === attribute.id).map(row =>
            <span className="is-learned" key={'proficiency:' + row.id}>{text(row, 'name')}</span>)}
        </div>)}</div>)}
      {section('Экипировка', 'Предметы из инвентаря, которые надеты на персонажа', 'sections.equipment',
        equipment.length ? <div className="RpSheet-equipment">{equipment.map(row => item(row, true))}</div> : <p>Ничего не экипировано</p>)}
      {section('Перки', 'Уникальные способности или изъяны', 'sections.perks',
        rows(snapshot.perks).length ? <div className="RpSheet-cards">{rows(snapshot.perks).map(row => {
          const origin = object(row.source);
          return <PerkCard key={Number(row.id)} mode="expanded" perk={{...object(row.definition), rarity: row.rarity}}
            source={sourceLabel(object(origin.definition), object(origin.context), lookups.attributes)}/>;
        })}</div> : <p>Перки отсутствуют</p>)}
      {section('Заклинания', 'Воплощение магической энергии в конкретной форме', 'sections.spells',
        rows(snapshot.spells).length ? <div className="RpSheet-cards">{rows(snapshot.spells).map(row =>
          <SpellCard key={Number(row.id)} mode="expanded" spell={object(row.definition)} path={object(row.path) as CatalogRow}/>)}</div>
          : <p>Заклинания отсутствуют</p>)}
      {section('Инвентарь', 'Всё, что персонаж носит с собой', 'sections.inventory',
        inventory.length ? <div className="RpSheet-cards">{inventory.map(row => item(row))}</div> : <p>Предметы отсутствуют</p>,
        <strong className="RpSheet-weight"><Icon name={uiIcon('resources.weight')}/>
          {Number(object(calculation.inventory_weight).value ?? 0)}/{value('carry_capacity')}</strong>)}
    </article>;
  }
}
