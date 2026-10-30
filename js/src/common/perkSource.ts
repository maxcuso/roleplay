import type {CatalogRow, Fields} from './catalogData';
import {object, text} from './catalogData';

/** Общая подпись конкретной выдачи для мастера и профиля, без словаря имён рас/навыков. */
export function sourceLabel(definition: Fields | undefined, context: Fields, attributes: CatalogRow[]): {name: string; icon?: string} {
  const row = definition ?? {};
  const kind = text(row, 'name_source');
  const name = kind ? text(context, kind + '_name') || text(row, 'name') : text(row, 'name');
  let icon = text(row, 'icon');
  if (row.icon_source === 'skill') {
    const slug = text(context, 'attribute_slug');
    icon = text(object(row.attribute_icons), slug) || text(attributes.find(attribute => attribute.slug === slug) ?? {}, 'icon') || icon;
  }
  return {name, icon};
}
