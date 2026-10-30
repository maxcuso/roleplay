import {definition, nameColumn, referenceFilter, statusFilter} from '../../../common/catalog/definitions/base';
import SkillEditor from '../../editors/SkillEditor';
import {initialFields, lookup, object} from '../../../common/catalog/helpers';
import {roman} from '../../../common/presentation';
import type {CatalogRow, Lookups} from '../../../common/catalog/types';

/** Три столбца с названиями привязанных перков; статус и операции предоставляет общая таблица. */
export default definition({
  key: 'skills', title: 'Навыки', icon: 'sections.training',
  columns: [
    nameColumn('attribute'),
    {key: 'attribute', title: 'Характеристика', render: (row, lookups) =>
      lookup(lookups, 'attributes', row.attribute_id)?.name ?? '—'},
    ...[1, 2, 3].map(level => ({
      key: 'level-' + level, title: roman(level),
      render: (row: CatalogRow, lookups: Lookups) => {
        const levels = Array.isArray(row.levels) ? row.levels.map(object) : [];
        const id = levels.find(value => Number(value.level) === level)?.perk_id;
        return lookup(lookups, 'perks', id)?.name ?? '—';
      },
    })),
  ],
  filters: [referenceFilter('attribute', 'Все характеристики', 'attributes'), statusFilter],
  // Новую неполную запись можно сохранить; включить её получится после выбора всех перков.
  initial: row => ({...initialFields(row, {
    attribute_id: null, levels: [1, 2, 3].map(level => ({level, perk_id: null})),
  }), enabled: row?.enabled ?? false}),
  editor: SkillEditor,
});