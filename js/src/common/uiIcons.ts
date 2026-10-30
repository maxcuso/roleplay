import icons from '../../../resources/presentation/icons.json';

/** Имена служебных значков берутся из одного файла. Опечатку в ключе проверяет TypeScript. */
export type UiIconName = keyof typeof icons;

// Webpack встраивает только таблицу замен. При обычной установке она пустая.
declare const ROLEPLAY_LOCAL_ICON_REPLACEMENTS: Readonly<Record<string, string>>;

/** Общая точка отображения. Исходные настройки и значения справочника не изменяются. */
export function resolveIcon(value: string): string {
  const original = value.trim();
  const replacements = ROLEPLAY_LOCAL_ICON_REPLACEMENTS;
  return Object.prototype.hasOwnProperty.call(replacements, original) ? replacements[original] : original;
}

export function uiIcon(name: UiIconName): string {
  const value = icons[name];
  // Эта проверка полезна и для вызовов из обычного JavaScript без проверки типов.
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error('Не задана служебная иконка: ' + name);
  }
  return resolveIcon(value);
}

/** Для сущности используем настройку справочника, без словарей по её названию или slug. */
export function catalogueIcon(value?: string | null): string {
  return value?.trim() ? resolveIcon(value) : uiIcon('fallback');
}
