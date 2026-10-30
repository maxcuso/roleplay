import type {CatalogRow, Fields} from '../catalogData';
export {text, number, object, ids, lookup, options} from '../catalogData';

/** Обратно отправляем только редактируемые поля. Системные ключи не меняются при переименовании. */
export function initialFields(row: CatalogRow | null, defaults: Fields): Fields {
  const result: Fields = {
    name: row?.name ?? '', description: row?.description ?? '', enabled: row?.enabled ?? true,
    sort_order: row?.sort_order ?? 0,
  };
  for (const [key, value] of Object.entries(defaults)) result[key] = row?.[key] ?? value;
  return result;
}