import Component from 'flarum/common/Component';
import type {Fields} from '../catalogData';
import {object, text} from '../catalogData';
import {roman} from '../presentation';

/** Текст нужен селекторам; разметка ниже изолирует письмо справа налево в русской строке. */
export function rankText(rank: unknown): string {
  const row = object(rank);
  return row.name ? text(row, 'letter') + ' ' + text(row, 'name') : 'Без ранга';
}

export default class RankLabel extends Component<{rank: Fields}> {
  view(): JSX.Element {
    const row = this.attrs.rank;
    const hint = 'Ранг ' + roman(Number(row.level)) + (row.description ? ' · ' + text(row, 'description') : '');
    return <span className="RpRank" title={hint}>
      <bdi dir="rtl" className="RpRank-letter">{text(row, 'letter')}</bdi><span>{text(row, 'name')}</span>
    </span>;
  }
}
