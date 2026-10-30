import rarities from '../../../resources/presentation/rarities.json';

export const rarityOptions = Object.fromEntries(Object.entries(rarities).map(([key, value]) => [key, value.name]));
export const timeOptions = {turn: 'ходов', minute: 'минут', hour: 'часов', day: 'дней'};
export const damageTiers = [
  {value: 81, name: 'Смертельный'}, {value: 27, name: 'Серьёзный'}, {value: 9, name: 'Средний'},
  {value: 3, name: 'Малый'}, {value: 1, name: 'Незначительный'},
];

/** Формы передаются в порядке «1 персонаж, 2 персонажа, 5 персонажей».
 * 11–14 всегда используют третью форму, даже если число заканчивается на 1–4.
 */
export function pluralForm(count: number, forms: readonly [string, string, string]): string {
  const lastTwo = Math.abs(count) % 100;
  if (lastTwo >= 11 && lastTwo <= 14) return forms[2];
  const last = lastTwo % 10;
  return last === 1 ? forms[0] : last >= 2 && last <= 4 ? forms[1] : forms[2];
}

export function rarityColor(key: string): string {
  return rarities[key as keyof typeof rarities]?.color ?? rarities.common.color;
}
/** Готовая форма для подсказок; названия и цвета редкости хранятся в одном справочнике. */
export function rarityNeuterName(key: string): string {
  return rarities[key as keyof typeof rarities]?.name_neuter ?? rarities.common.name_neuter;
}
export function pathColor(color: string): string {
  return /^#[0-9a-f]{6}$/i.test(color) ? color : 'var(--primary-color)';
}
export function roman(value: number): string {
  if (!Number.isInteger(value) || value < 1 || value > 100) return '';
  let result = '';
  for (const [amount, sign] of [[100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']] as const) {
    while (value >= amount) { result += sign; value -= amount; }
  }
  return result;
}

/** Урон хранится числом. Ступени нормализуются только для вывода. */
export function damage(value: number): string {
  if (!Number.isInteger(value) || value <= 0) return 'Нет';
  const parts: string[] = [];
  for (const tier of damageTiers) {
    const count = Math.floor(value / tier.value);
    value %= tier.value;
    if (count) parts.push(tier.name + (count > 1 ? ' ×' + count : ''));
  }
  return parts.join(' + ');
}
export function time(value: number, unit: string): string {
  const units: Record<string, string> = {turn: 'х.', minute: 'мин.', hour: 'ч.', day: 'дн.'};
  return value > 0 && units[unit] ? value + ' ' + units[unit] : '';
}
export function safeImage(value: string): string | undefined {
  if (/^\/role\/media\/races\/[a-zA-Z0-9_-]+\.(?:png|jpe?g|webp|svg)\/file$/.test(value)) return value;
  if (/^\/assets\/[^\s\\]*$/.test(value) && !value.includes('..')) return value;
  try {
    const url = new URL(value);
    if (['http:', 'https:'].includes(url.protocol) && !url.username && !url.password) return value;
  } catch {}
  return undefined;
}
/** Цена хранится с точностью 0,001. Сначала считаем всю стопку, затем
 * отбрасываем дробную часть. Целую и дробную части умножаем отдельно:
 * это не даёт 0,29 × 100 ошибочно превратиться в 28 из-за погрешности float.
 */
export function itemStackPrice(price: number, quantity = 1): number {
  const whole = Math.trunc(price);
  const fraction = Math.round((price - whole) * 1000);
  return whole * quantity + Math.floor(fraction * quantity / 1000);
}
