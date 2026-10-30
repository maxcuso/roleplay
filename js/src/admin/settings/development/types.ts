import type {Lookups} from '../../../common/catalogData';

export type AttributeKey = 'physiology' | 'agility' | 'magic' | 'charisma';
export type RuleType = 'skill' | 'proficiency' | 'automatic' | 'melee_damage' | 'ranged_damage' | 'magic_damage' | 'ignored_damage' | 'circle';
export interface DevelopmentRule {id: string; level: number; type: RuleType; value: number | string; description?: string | null}
export interface AttributeDevelopment {growth: Record<string, {initial: number; per_level: number}>; rules: DevelopmentRule[]; initial_description?: string | null}
export interface DevelopmentConfiguration {
  maximum_level: number;
  skill_minimums: Record<number, number>;
  experience_costs: {minimum: number; cost: number}[];
  spell_learning_costs?: Record<number, number>;
  attributes: Record<AttributeKey, AttributeDevelopment>;
}
export interface DevelopmentView {revision: number; configuration: DevelopmentConfiguration; lookups: Lookups}

/** Разные строки остаются независимыми. Совпадение уровня само по себе не объединяет выдачи. */
export function sameThreshold(a: DevelopmentRule, b: DevelopmentRule): boolean {
  return a.level === b.level && a.type === b.type && (a.type !== 'automatic' || a.value === b.value);
}
