import {object, text, type Fields} from '../catalogData';
import {damage} from '../presentation';

/** Берём тот же серверный расчёт, который показан в плашке: максимум одной атаки
 * или чистую базу, если доступных атак нет. Урон разных атак не складываем. */
export function combatValue(calculation: Fields, stat: string): Fields {
  if (stat === 'ignored_damage') return object(object(calculation.stats)[stat]);
  const attack = object(object(calculation.maximum_damage)[stat]);
  return object(attack.damage ?? object(calculation.base_damage)[stat]);
}

/** В подсказке только готовые слагаемые сервера, включая округлённые бонусы.
 * Процентная группа хранит общий вклад: перечисляем её реальные источники вместе,
 * не распределяя округление между ними повторным расчётом на клиенте. */
export function combatHint(value: Fields, title: string, baseLabel = 'Базовый урон'): string {
  const parts = Array.isArray(value.parts) ? value.parts as Fields[] : [];
  const lines = parts.flatMap(part => {
    const amount = Number(part.value ?? 0), source = object(part.source);
    if (!amount && source.kind !== 'attribute') return [];
    const effects = Array.isArray(source.effects) ? source.effects as Fields[] : [];
    const label = source.kind === 'attribute' ? baseLabel
      : effects.length ? [...new Set(effects.map(effect => text(effect, 'label')).filter(Boolean))].join(', ')
      : text(source, 'label');
    return label ? [label + ' – ' + (amount < 0 ? '−' : '') + damage(Math.abs(amount))] : [];
  });
  return [title, ...lines].join('\n');
}
