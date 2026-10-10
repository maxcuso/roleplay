import type CreationState from './CreationState';
import {lookup, object, text} from '../../common/catalogData';
import type {CatalogRow, Fields} from '../../common/catalogData';
import type {Grant} from './types';
import {sourceLabel} from '../../common/perkSource';

export function race(state: CreationState): CatalogRow | undefined {
  return lookup(state.view!.catalog.definitions, 'races', state.view!.draft.payload.race_id);
}
export function raceRules(state: CreationState): Fields { return object(race(state)?.rules); }

/** Только переключение выбранных ID. Допустимый список и лимит приходят от сервера. */
export function toggle(values: number[], id: number): number[] {
  return values.includes(id) ? values.filter(value => value !== id) : [...values, id];
}
export function signed(value: number): string { return (value > 0 ? '+' : '') + value; }

/** Источник берём из выдачи; раса/навык задают подпись согласно настройкам источника. */
export function source(state: CreationState, grant: Grant): {name: string; icon?: string} {
  return sourceLabel(lookup(state.view!.catalog.definitions, 'sources', grant.source_id), grant.context,
    state.view!.catalog.definitions.attributes);
}

/** Свободное распределение: границы каждой характеристики и общий запас очков.
 * Уменьшение разрешено и при превышенном бюджете, чтобы исправить старый черновик.
 */
export function canAdjustAttribute(distribution: number[], values: number[], index: number, delta: -1 | 1): boolean {
  if (index < 0 || index >= values.length || values.length !== distribution.length) return false;
  const next = values[index] + delta;
  if (delta === -1) return next >= Math.min(...distribution);
  const budget = distribution.reduce((sum, value) => sum + value, 0);
  const spent = values.reduce((sum, value) => sum + value, 0);
  return next <= Math.max(...distribution) && spent + delta <= budget;
}
