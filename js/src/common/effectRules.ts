import configuration from '../../../resources/rules/effects.json';

/** Один файл возможностей читают сервер и редакторы. Формулы остаются в PHP. */
export const effectRules = configuration;
export type EffectMechanic = keyof typeof configuration.mechanics;
export function effectMechanic(key: unknown) {
  return configuration.mechanics[key as EffectMechanic] ?? configuration.mechanics.text;
}
export function isDamageTarget(key: unknown): boolean {
  return configuration.damage_stats.includes(String(key));
}
export function isAttackTarget(key: unknown): boolean {
  return configuration.attack_stats.includes(String(key));
}
/** Контекстные эффекты не должны выдаваться за постоянные бонусы анкеты. */
export function effectConditions(key: unknown): string[] {
  const rule = effectMechanic(key);
  return 'conditions' in rule ? rule.conditions : Object.keys(effectRules.conditions);
}
