import type Mithril from 'mithril';
import {damageTiers, pluralForm, roman} from '../../common/presentation';
import {guideText} from './guideText';

interface GuideRule {
  id: string; level: number; type: string; value: number | string;
  description: string; proficiency: string | null;
}
export interface GuideDevelopmentData {
  ranks: Array<{slug: string; name: string; level: number; letter: string; description: string | null}>;
  advancement: {
    maximum_level: number; skill_minimums: Record<number, number>;
    experience_costs: Array<{minimum: number; cost: number}>;
  };
  development: Record<string, {
    initial_description: string | null; initial_stats: Record<string, number>; rules: GuideRule[];
  }>;
}

/** Подстановки — текст и безопасные span, а не HTML из базы. Сохраняем оформление Timeline. */
function interpolate(template: string, values: Record<string, Mithril.Children>): Mithril.Children {
  return template.split(/(\{[a-z_]+\})/g).map(part => part.startsWith('{')
    ? values[part.slice(1, -1)] ?? '' : part);
}

function damageLabel(value: number): Mithril.Children {
  if (value <= 0) return <span className="paper-highlight">Нет</span>;
  const parts: Mithril.Children[] = [];
  for (const tier of damageTiers) {
    const count = Math.floor(value / tier.value);
    value %= tier.value;
    if (!count) continue;
    if (parts.length) parts.push(' + ');
    // На странице двойная степень называлась «двойной Средний» — сохраняем формулировку.
    const label = count === 2 ? 'двойной ' + tier.name : tier.name + (count > 1 ? ' ×' + count : '');
    parts.push(<span className="paper-highlight">{label}</span>);
  }
  return parts;
}

function ruleText(rule: GuideRule): Mithril.Children {
  const count = Number(rule.value);
  return interpolate(rule.description.replace(/\.$/, ''), {
    value: String(rule.value), damage: damageLabel(count), circle: count ? roman(count) : 'без доступа',
    proficiency: rule.proficiency ?? String(rule.value),
    skill_levels: count + ' ' + pluralForm(count, ['уровень', 'уровня', 'уровней']),
    proficiency_choices: count === 1 ? 'одно из владений оружием на выбор'
      : count + ' ' + pluralForm(count, ['владение', 'владения', 'владений']) + ' оружием на выбор',
  });
}

function levelIcon(slug: string, rules: GuideRule[]): string {
  if (rules.some(rule => rule.type === 'skill')) return 'sections.training';
  if (rules.some(rule => ['automatic', 'proficiency'].includes(rule.type))) return 'sections.equipment';
  if (rules.some(rule => rule.type === 'ignored_damage')) return 'combat.defense';
  if (rules.some(rule => rule.type === 'circle') || slug === 'magic' && rules.some(rule => rule.level === 1)) return 'sections.paths';
  return 'settings.development';
}

export function mountDevelopment(root: HTMLElement, data: GuideDevelopmentData): () => void {
  const mounted: HTMLElement[] = [];
  // Таблица Гильдии использует ту же шкалу, что назначение ранга в профиле.
  root.querySelectorAll<HTMLElement>('[data-guild-ranks]').forEach(slot => {
    m.render(slot, data.ranks.length ? data.ranks.map(rank => <tr key={rank.slug} title={guideText(rank.description) || undefined}>
      <td>{roman(rank.level)}</td><td><bdi dir="rtl">{rank.letter}</bdi></td><td>{rank.name}</td>
    </tr>) : <tr><td colspan={3}>В справочнике пока нет доступных рангов</td></tr>);
    mounted.push(slot);
  });
  // Условия рангов остаются читаемой таблицей HTML, имена приходят из общего справочника.
  root.querySelectorAll<HTMLElement>('[data-rank-name]').forEach(slot => {
    const rank = data.ranks.find(rank => rank.level === Number(slot.dataset.rankName));
    const row = slot.closest('tr');
    if (row) row.hidden = !rank;
    m.render(slot, rank ? roman(rank.level) + ' — ' + rank.name : '');
    mounted.push(slot);
  });
  // Требования и цены используют те же настройки, что мастер и повышение в профиле.
  root.querySelectorAll<HTMLElement>('[data-skill-minimum]').forEach(slot => {
    m.render(slot, String(data.advancement.skill_minimums[Number(slot.dataset.skillMinimum)]));
    mounted.push(slot);
  });
  root.querySelectorAll<HTMLElement>('[data-experience-costs]').forEach(slot => {
    const ranges = [...data.advancement.experience_costs].sort((a, b) => a.minimum - b.minimum);
    m.render(slot, ranges.map((range, index) => {
      const last = Math.min(data.advancement.maximum_level, (ranges[index + 1]?.minimum ?? data.advancement.maximum_level + 1) - 1);
      return range.minimum <= last && <tr key={range.minimum}>
        <td>{range.minimum === last ? range.minimum : range.minimum + '–' + last}</td><td>{range.cost.toLocaleString('ru-RU')}</td>
      </tr>;
    }));
    mounted.push(slot);
  });
  root.querySelectorAll<HTMLElement>('[data-attribute-development]').forEach(slot => {
    const slug = slot.dataset.attributeDevelopment!;
    const attribute = data.development[slug];
    mounted.push(slot);
    if (!attribute) {m.render(slot, <li>Уровни развития пока недоступны</li>); return;}
    const levels = new Map<number, GuideRule[]>();
    if (attribute.initial_description) levels.set(1, []);
    attribute.rules.forEach(rule => {
      if (!levels.has(rule.level)) levels.set(rule.level, []);
      levels.get(rule.level)!.push(rule);
    });
    m.render(slot, [...levels].sort(([a], [b]) => a - b).map(([level, rules]) => {
      const texts: Mithril.Children[] = [];
      if (level === 1 && attribute.initial_description) texts.push(interpolate(attribute.initial_description.replace(/\.$/, ''), attribute.initial_stats));
      rules.forEach(rule => texts.push(ruleText(rule)));
      return <li key={level}><span>{level}</span><div>
        <i className="Icon" data-icon={levelIcon(slug, rules)} aria-hidden="true"/>
        <span>{texts.flatMap((text, index) => index ? ['. ', text] : [text])}</span>
      </div></li>;
    }));
  });
  return () => mounted.forEach(slot => m.render(slot, []));
}
