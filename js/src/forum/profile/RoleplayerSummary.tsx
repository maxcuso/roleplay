import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import type {RoleplayerStats} from './types';
import {pluralForm} from '../../common/presentation';

/** Только отображение: эти показатели принадлежат игроку, а не выбранной анкете. */
export default class RoleplayerSummary extends Component<{stats: RoleplayerStats}> {
  view(): Mithril.Children {
    const stats = this.attrs.stats;
    return <dl className="RpRoleplayer" aria-label="Статистика ролевика">
      <div className="RpRoleplayer-experience" title="Весь заработанный опыт по всем персонажам, без стартового опыта">
        <dt>Опыт ролевика</dt><dd>{stats.earned_experience.toLocaleString('ru-RU')}</dd>
      </div>
      <div className="RpRoleplayer-characters" title="Количество активных персонажей">
        <dt>{pluralForm(stats.active_characters, ['Персонаж', 'Персонажа', 'Персонажей'])}</dt><dd>{stats.active_characters}</dd>
      </div>
    </dl>;
  }
}
