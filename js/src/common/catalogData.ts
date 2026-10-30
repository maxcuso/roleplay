/** Данные справочников используются в админке, мастере и профиле. Здесь нет зависимости от экранов. */
export interface CatalogRow extends Record<string, unknown> {
  id: number;
  name: string;
  enabled?: boolean;
  revision?: number;
}
export type Fields = Record<string, unknown>;
export type Lookups = Record<string, CatalogRow[]>;
export function text(row: Fields, field: string): string { return String(row[field] ?? ''); }
export function number(row: Fields, field: string): number { return Number(row[field] ?? 0); }
export function object(value: unknown): Fields {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Fields : {};
}
export function ids(value: unknown): number[] { return Array.isArray(value) ? value.map(Number) : []; }
export function lookup(lookups: Lookups, key: string, id: unknown): CatalogRow | undefined {
  return (lookups[key] ?? []).find(row => row.id === Number(id));
}
export function options(lookups: Lookups, key: string, useSlug = false): Record<string, string> {
  return Object.fromEntries((lookups[key] ?? []).map(row => [
    useSlug ? text(row, 'slug') : String(row.id), row.name + (row.enabled === false ? ' (выключено)' : ''),
  ]));
}