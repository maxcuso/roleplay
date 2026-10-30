import type Mithril from 'mithril';
import PerkCard from '../../common/cards/PerkCard';
import type {CatalogRow, Fields} from '../../common/catalogData';
import {sourceLabel} from '../../common/perkSource';
import {roman} from '../../common/presentation';
import type {GuideRace} from './GuideRaces';
import {guideText, guideParagraphs} from './guideText';

interface GuideSkill {
  slug: string;
  name: string;
  description: string | null;
  attribute_slug: string | null;
  source: Fields | null;
  levels: Array<{level: number; perk: CatalogRow | null}>;
}
export interface GuideCatalogData {
  attributes: Array<CatalogRow & {slug: string; icon?: string | null}>;
  perks: CatalogRow[];
  skills: GuideSkill[];
  races: GuideRace[];
}

/** HTML задаёт места для характеристик, а число и состав навыков определяет справочник.
 * Карточки перков общие с мастером и профилем; связи читаются по ключам, не по названиям.
 */
export function mountPerkCards(root: HTMLElement, data: GuideCatalogData): () => void {
  const mounted: HTMLElement[] = [];
  const render = (slot: HTMLElement, children: Mithril.Children) => {
    m.render(slot, children);
    mounted.push(slot);
  };
  const examples = root.querySelector<HTMLElement>('[data-roleplay-block="perk-examples"]');
  if (examples) render(examples, data.perks.length
    ? data.perks.map(perk => <PerkCard key={perk.id} mode="expanded" perk={{...perk, description: guideText(perk.description)}}/>)
    : <p role="status">В справочнике пока нет доступных перков</p>);

  const skillCards = (skill: GuideSkill): Mithril.Children => {
    const source = sourceLabel(skill.source ?? undefined,
      {skill_name: skill.name, attribute_slug: skill.attribute_slug}, data.attributes);
    return skill.levels.map(({level, perk}) => <div className="Step Step--perk" key={level}>
      <span className="Badge Badge--level" aria-label={level + ' уровень'}>{roman(level)}</span>
      {perk ? <PerkCard mode="expanded" perk={{...perk, description: guideText(perk.description)}} source={source}/>
        : <p role="status">Перк этого уровня пока не настроен</p>}
    </div>);
  };
  // API уже отсортирован по sort_order и ID. Чередование начинается заново в каждой характеристике.
  root.querySelectorAll<HTMLElement>('[data-attribute-skills]').forEach(slot => {
    const skills = data.skills.filter(skill => skill.attribute_slug === slot.dataset.attributeSkills);
    render(slot, skills.length ? skills.map((skill, index) => m.fragment({key: skill.slug}, [
      index > 0 && <hr/>,
      <div className={'Step Step--wide-aside' + (index % 2 ? ' Step--reverse' : '')} data-skill={skill.slug}>
        <div className="Skill">
          <h4><span className="Badge" aria-hidden="true">
            <i data-attribute-icon={skill.attribute_slug!} className="Icon" aria-hidden="true"/>
          </span>{skill.name.toLocaleUpperCase('ru-RU')}</h4>
          {guideParagraphs(skill.description).map(text => <p>{text}</p>)}
        </div>
        <div className="Grid Grid--stack" data-skill-perks={skill.slug}>{skillCards(skill)}</div>
      </div>,
    ])) : <p role="status">У этой характеристики пока нет доступных навыков</p>);
  });
  root.querySelectorAll<HTMLElement>('[data-attribute-skill-list]').forEach(slot => {
    const skills = data.skills.filter(skill => skill.attribute_slug === slot.dataset.attributeSkillList);
    render(slot, skills.length ? skills.map(skill => <span className="paper-highlight" key={skill.slug}>{skill.name}</span>)
      : 'Нет доступных навыков');
  });

  return () => mounted.forEach(slot => m.render(slot, []));
}
