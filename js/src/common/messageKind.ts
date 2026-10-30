import messages from '../../../resources/presentation/messages.json';

export type MessageKind = 'operation' | 'input' | 'condition';

// Сопоставляем уже сформированный текст, сохраняя чистые классы состояния без
// зависимости от Flarum. Значения в фигурных скобках — только подстановки текста.
const patterns = messages.map(row => ({
  kind: row.kind as MessageKind,
  pattern: new RegExp('^(?:[^\\n]+: )?' + row.text.split(/(\{[^}]+\})/).map(part =>
    /^\{[^}]+\}$/.test(part) ? '.+?' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('') + '$', 'u'),
}));

export function messageKind(message: string): MessageKind {
  return patterns.find(row => row.pattern.test(message))?.kind ?? 'operation';
}

/** Правка формы убирает предупреждение заполнения, но не скрывает конфликт или сбой. */
export function clearInputFeedback(state: {error: string}): void {
  if (state.error && messageKind(state.error) === 'input') state.error = '';
}
