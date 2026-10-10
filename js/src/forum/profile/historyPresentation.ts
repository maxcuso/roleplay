import {lookup, object, text, type Fields, type Lookups} from '../../common/catalogData';
import {catalogueIcon, uiIcon, type UiIconName} from '../../common/uiIcons';
import {roman} from '../../common/presentation';

export interface HistoryEvent {id: number; type: string; created_at: string; details: Fields}
export interface HistoryDay {key: string; label: string; date: string; events: HistoryEvent[]}
export interface HistoryResource {kind: 'experience' | 'gold'; value: number; label: string}

const actions: Record<string, {label: string; icon: UiIconName}> = {
  grant_perk: {label: 'Выдан перк', icon: 'sections.perks'},
  remove_perk: {label: 'Изъят перк', icon: 'sections.perks'},
  grant_spell: {label: 'Выдано заклинание', icon: 'sections.spells'},
  remove_spell: {label: 'Изъято заклинание', icon: 'sections.spells'},
  grant_item: {label: 'Выданы предметы', icon: 'sections.items'},
  remove_item: {label: 'Изъяты предметы', icon: 'sections.items'},
  set_rank: {label: 'Изменён ранг', icon: 'management.rank'},
};

/** SQL-время сервера хранится в UTC. День и часы показываем в часовом поясе браузера,
 * чтобы запись около полуночи попадала в ту же группу, что и её видимое время.
 */
export function historyDate(value: string): Date | null {
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? value.replace(' ', 'T') + 'Z' : value;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

const dayKey = (date: Date): string => [date.getFullYear(), date.getMonth(), date.getDate()].join('-');
export function historyDays(events: HistoryEvent[], now = new Date()): HistoryDay[] {
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  const groups = new Map<string, HistoryDay>();
  // Порядок сервера сохраняется, в том числе для событий с одинаковой секундой.
  for (const event of events) {
    const date = historyDate(event.created_at);
    const key = date ? dayKey(date) : 'unknown';
    if (!groups.has(key)) {
      const formatted = date?.toLocaleDateString('ru-RU', {day: 'numeric', month: 'long', year: 'numeric'}) ?? '';
      const relative = key === dayKey(now) ? 'Сегодня' : key === dayKey(yesterday) ? 'Вчера' : '';
      groups.set(key, {key, label: relative || formatted || 'Дата неизвестна', date: relative ? formatted : '', events: []});
    }
    groups.get(key)!.events.push(event);
  }
  return [...groups.values()];
}

export function historyResources(details: Fields): HistoryResource[] {
  const resources: HistoryResource[] = [];
  const add = (kind: HistoryResource['kind'], value: number): void => {
    if (!Number.isFinite(value) || value === 0) return;
    const amount = Math.abs(value).toLocaleString('ru-RU');
    resources.push({kind, value, label: (value > 0 ? '+' : '−') + amount + (kind === 'gold' ? ' золота' : ' опыта')});
  };
  if (details.experience_spent) add('experience', -Number(details.experience_spent));
  if (details.action === 'reward') {
    add('experience', Number(details.experience));
    add('gold', Number(details.gold));
  } else if (details.action === 'balance') {
    add(details.resource === 'gold' ? 'gold' : 'experience', Number(details.delta));
  }
  return resources;
}

/** Название из журнала имеет приоритет: правка справочника не должна менять прошлое.
 * В старых записях изъятия название отсутствует; для них используем доступный справочник.
 */
export function historyHeading(event: HistoryEvent, lookups: Lookups): {label: string; icon: string; caption: string} {
  const details = event.details, kind = text(details, 'kind');
  const catalog = kind === 'proficiency' ? 'proficiencies' : kind + 's';
  const removed = object(details.removed);
  const target = lookup(lookups, catalog, removed[kind + '_id'] ?? details.target_id);
  const name = text(details, 'name') || target?.name || '';
  if (event.type === 'character_developed') {
    const attribute = kind === 'attribute' ? target : lookup(lookups, 'attributes', target?.attribute_id);
    const icon = attribute?.icon ? catalogueIcon(text(attribute, 'icon')) : uiIcon('sections.training');
    const level = kind === 'attribute' ? text(details, 'level') : roman(Number(details.level));
    const caption = kind === 'proficiency' ? 'Изучено владение'
      : kind === 'skill' ? (Number(details.level) === 1 ? 'Изучен навык' : 'Навык повышен') : '';
    return {label: (name || 'Развитие персонажа') + (level ? ' · ' + level : ''), icon, caption};
  }
  if (details.action === 'reward' || details.action === 'balance') {
    const amount = Number(details.delta ?? details.experience) || Number(details.gold);
    return {label: amount < 0 ? 'Списаны ресурсы' : 'Начислена награда', icon: uiIcon('management.title'), caption: ''};
  }
  const action = actions[text(details, 'action')];
  const quantity = kind === 'item' && Number(details.quantity) > 0 ? ' ×' + Number(details.quantity) : '';
  return {label: (action?.label ?? 'Изменение') + (name && details.action !== 'set_rank' ? ' · ' + name : '') + quantity,
    icon: uiIcon(action?.icon ?? 'actions.history'), caption: ''};
}
