/** Статусы едины для анкеты, переключателя персонажей и истории профиля. */
export const characterStatuses: Record<string, string> = {
  creating: 'Создаётся', active: 'Активен', dead: 'Погиб', archived: 'В архиве',
};
export function characterStatus(value: string): string { return characterStatuses[value] ?? 'Неизвестный статус'; }