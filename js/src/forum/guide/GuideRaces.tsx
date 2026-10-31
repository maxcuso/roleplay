import type Mithril from 'mithril';
import type {CatalogRow, Fields} from '../../common/catalogData';
import PerkCard from '../../common/cards/PerkCard';
import {sourceLabel} from '../../common/perkSource';
import {pluralForm, safeImage} from '../../common/presentation';
import {guideText, guideParagraphs} from './guideText';

export interface GuideRace {
  slug: string; name: string; description: string | null; quote: string | null; image: string | null;
  min_age: number | null; max_age: number | null; average_height: string | null;
  distinctive_traits: string | null; background_color: string | null;
  attribute_bonuses: {
    fixed_bonuses: Record<string, number>; choice_attributes: string[]; attribute_bonus: number; attribute_count: number;
  };
  source: Fields | null; fixed_perks: CatalogRow[]; choice_perks: CatalogRow[]; perk_choice_count: number;
}

function lifespan(race: GuideRace): string {
  const {min_age: min, max_age: max} = race;
  if (min == null && max == null) return '—';
  if (min === max) return min + ' ' + pluralForm(min!, ['год', 'года', 'лет']);
  return (min == null ? 'До ' + max : max == null ? 'От ' + min : min + '–' + max) + ' лет';
}

/** Формулировки бонусов строятся из действующих правил, без словаря рас. */
function bonuses(race: GuideRace, attributes: Array<CatalogRow & {slug: string}>): Mithril.Children {
  const rules = race.attribute_bonuses;
  const names = new Map(attributes.map(attribute => [attribute.slug, attribute.name]));
  const accents: Record<string, string> = {physiology: 'ember', agility: 'air', magic: 'violet', charisma: 'gold'};
  const dative: Record<string, string> = {Физиология: 'Физиологии', Ловкость: 'Ловкости', Магия: 'Магии', Харизма: 'Харизме'};
  const labels = Object.entries(rules.fixed_bonuses).map(([slug, value]) => {
    const name = names.get(slug) ?? slug;
    return {label: (value > 0 ? '+' : '') + value + (dative[name] ? ' к ' + dative[name] : ' · ' + name),
      accent: accents[slug]};
  });
  if (rules.attribute_count) {
    const count = ['', 'одной', 'двум', 'трём', 'четырём'][rules.attribute_count] ?? String(rules.attribute_count);
    const limited = rules.choice_attributes.length < attributes.length;
    labels.push({label: '+' + rules.attribute_bonus + ' к ' + count + ' на выбор'
      + (limited ? ': ' + rules.choice_attributes.map(slug => names.get(slug) ?? slug).join(', ') : ''), accent: 'water'});
  }
  return labels.length ? labels.map(({label, accent}) => <span className="paper-highlight" data-accent={accent}>{label}</span>) : 'Нет';
}

/** Полные блоки рас в прежних Step--race / Facts / Grid. Текст из базы не считается HTML. */
export function mountRaces(root: HTMLElement, data: {races: GuideRace[]; attributes: Array<CatalogRow & {slug: string}>}): () => void {
  const mounted: HTMLElement[] = [];
  root.querySelectorAll<HTMLElement>('[data-races]').forEach(slot => {
    mounted.push(slot);
    m.render(slot, data.races.length ? data.races.map(race => {
      const image = safeImage(race.image ?? '');
      const color = /^#[0-9a-f]{6}$/i.test(race.background_color ?? '') ? race.background_color! : undefined;
      const source = sourceLabel(race.source ?? undefined, {race_name: race.name}, data.attributes);
      const card = (perk: CatalogRow, group: string) => <PerkCard key={group + perk.id} mode="expanded"
        perk={{...perk, description: guideText(perk.description)}} source={source}/>;
      return <section className="Section Section--nested" data-race={race.slug} key={race.slug}
        style={color ? {'--role-accent': color} : undefined}>
        <div className="Step Step--race">
          <div><h4>{race.name.toLocaleUpperCase('ru-RU')}</h4>{race.quote && <em>{race.quote}</em>}</div>
          <figure className="Figure Figure--cutout" aria-label={'Изображение расы «' + race.name + '»'}>
            {image ? <img src={image} alt={race.name} width="150" height="150" loading="lazy" decoding="async"/>
              : <i data-icon="fields.image" className="Icon" aria-hidden="true"/>}
          </figure>
          {guideParagraphs(race.description).map(text => <p>{text}</p>)}
        </div>
        <dl className="Facts Facts--wide-third">
          <div data-race-field="lifespan"><dt><i data-icon="actions.history" className="Icon" aria-hidden="true"/>Срок жизни</dt>
            <dd><p>{lifespan(race)}</p></dd></div>
          <div data-race-field="height"><dt><i data-icon="resources.weight" className="Icon" aria-hidden="true"/>Средний рост</dt>
            <dd><p>{guideText(race.average_height) || '—'}</p></dd></div>
          <div data-race-field="traits"><dt><i data-icon="sections.characters" className="Icon" aria-hidden="true"/>Отличительные черты</dt>
            <dd><p>{guideText(race.distinctive_traits) || '—'}</p></dd></div>
          <div data-race-field="bonuses"><dt><i data-icon="settings.development" className="Icon" aria-hidden="true"/>Бонусы</dt>
            <dd><p>{bonuses(race, data.attributes)}</p></dd></div>
        </dl>
        <div className="Grid" data-race-perks={race.slug}>
          {race.fixed_perks.map(perk => card(perk, 'fixed-'))}
          {race.choice_perks.length > 0 && <p className="SectionKicker" style={{gridColumn: '1 / -1'}}>Перки на выбор — {race.perk_choice_count}</p>}
          {race.choice_perks.map(perk => card(perk, 'choice-'))}
          {!race.fixed_perks.length && !race.choice_perks.length && <p role="status">У этой расы нет доступных расовых перков</p>}
        </div>
        <hr/>
      </section>;
    }) : <p role="status">В справочнике пока нет доступных рас</p>);
  });
  return () => mounted.forEach(slot => m.render(slot, []));
}
