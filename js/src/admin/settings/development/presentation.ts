import {damage, roman} from '../../../common/presentation';
import {text, type Lookups} from '../../../common/catalogData';
import type {UiIconName} from '../../../common/uiIcons';
import type {AttributeKey, DevelopmentRule, RuleType} from './types';

export const attributeKeys: AttributeKey[] = ['physiology', 'agility', 'magic', 'charisma'];
export const ruleTypes: Record<RuleType, {label: string; icon: UiIconName}> = {
  skill: {label: 'Выбор навыка', icon: 'wizard.training'},
  proficiency: {label: 'Выбор владения', icon: 'item.meleeWeapon'},
  automatic: {label: 'Автоматическое владение', icon: 'actions.select'},
  melee_damage: {label: 'Урон в ближнем бою', icon: 'combat.melee'},
  ranged_damage: {label: 'Урон в дальнем бою / метательным оружием', icon: 'combat.ranged'},
  magic_damage: {label: 'Магический урон', icon: 'combat.magic'},
  ignored_damage: {label: 'Игнорируемый урон', icon: 'combat.defense'},
  circle: {label: 'Круг заклинаний', icon: 'sections.spells'},
};
export const allowedTypes: Record<AttributeKey, RuleType[]> = {
  physiology: ['skill', 'proficiency', 'automatic', 'melee_damage', 'ignored_damage'],
  agility: ['skill', 'proficiency', 'automatic', 'ranged_damage'],
  magic: ['skill', 'proficiency', 'automatic', 'magic_damage', 'circle'],
  charisma: ['skill', 'proficiency', 'automatic'],
};
export const growthLabels: Record<string, {label: string; icon: UiIconName}> = {
  stamina_max: {label: 'Выносливость', icon: 'resources.stamina'},
  mana_max: {label: 'Мана', icon: 'resources.mana'},
  carry_capacity: {label: 'Грузоподъёмность, кг', icon: 'resources.weight'},
};
export function attributeDefinition(lookups: Lookups, key: AttributeKey) {
  return (lookups.attributes ?? []).find(row => row.slug === key);
}
export function ruleValue(rule: DevelopmentRule, lookups: Lookups): string {
  if (rule.type === 'skill' || rule.type === 'proficiency') return '+' + rule.value;
  if (rule.type === 'circle') return Number(rule.value) > 0 ? roman(Number(rule.value)) : 'Нет доступа';
  if (rule.type === 'automatic') {
    const row = (lookups.proficiencies ?? []).find(row => row.slug === rule.value);
    return row ? text(row, 'name') : String(rule.value);
  }
  return damage(Number(rule.value));
}
