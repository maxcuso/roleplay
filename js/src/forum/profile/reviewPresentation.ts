import type {UiIconName} from '../../common/uiIcons';
import type {ReviewPart} from './types';

export const reviewParts: Record<ReviewPart, string> = {mechanics: 'Техническая часть', literature: 'Литературная часть'};
const statuses: Record<string, {label: string; icon: UiIconName}> = {
  draft: {label: 'Ожидает отправки', icon: 'review.waiting'},
  review: {label: 'На проверке', icon: 'review.waiting'},
  changes_requested: {label: 'Требуются исправления', icon: 'actions.edit'},
  approved: {label: 'Одобрена', icon: 'actions.select'},
};

/** Подписи одинаковы в панели и истории; черновик литературной части ещё заполняется. */
export function reviewStatus(part: ReviewPart, status: string): {label: string; icon: UiIconName} {
  return part === 'literature' && status === 'draft'
    ? {label: 'В работе', icon: 'actions.edit'} : statuses[status] ?? {label: status, icon: 'review.waiting'};
}
