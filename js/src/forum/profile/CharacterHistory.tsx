import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {object, text, type Lookups} from '../../common/catalogData';
import RankLabel from '../../common/character/RankLabel';
import {uiIcon} from '../../common/uiIcons';
import {historyDate, historyDays, historyHeading, historyResources, type HistoryEvent} from './historyPresentation';

export type {HistoryEvent} from './historyPresentation';
interface Attrs {events: HistoryEvent[]; lookups?: Lookups}

/** Только отображение разрешённого сервером журнала. Начисления и причины не загружаем отдельно. */
export default class CharacterHistory extends Component<Attrs> {
  view(): Mithril.Children {
    if (!this.attrs.events.length) return <p className="RpCharacterHistory-empty">История пока пуста.</p>;
    return <div className="RpCharacterHistory">{historyDays(this.attrs.events).map(day =>
      <section className="RpCharacterHistory-day" key={day.key} aria-label={day.label}>
        <h3>{day.label}{day.date && <span>{day.date}</span>}</h3>
        <ul>{day.events.map(event => this.event(event))}</ul>
      </section>)}</div>;
  }

  private event(event: HistoryEvent): Mithril.Children {
    const details = event.details;
    const heading = historyHeading(event, this.attrs.lookups ?? {});
    const resources = historyResources(details);
    const beforeRank = object(details.before), afterRank = object(details.after);
    const date = historyDate(event.created_at);
    const exactTime = date?.toLocaleString('ru-RU', {day: 'numeric', month: 'long', year: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit'}) ?? event.created_at;
    return <li key={event.id}>
      <span className="RpCharacterHistory-icon" aria-hidden="true"><Icon name={heading.icon}/></span>
      <div className="RpCharacterHistory-content">
        <div className="RpCharacterHistory-heading"><strong>{heading.label}</strong>
          <time datetime={date?.toISOString()} title={exactTime}>{date?.toLocaleTimeString('ru-RU',
            {hour: '2-digit', minute: '2-digit'}) ?? '—'}</time></div>
        {heading.caption && <div className="RpCharacterHistory-caption">{heading.caption}</div>}
        {resources.length > 0 && <div className="RpCharacterHistory-resources">{resources.map(resource =>
          <span key={resource.kind} className={'RpCharacterHistory-resource is-' + resource.kind + (resource.value > 0 ? ' is-positive' : '')}>
            <Icon name={uiIcon(resource.kind === 'gold' ? 'resources.gold' : 'resources.activeExperience')}/>{resource.label}
          </span>)}</div>}
        {details.action === 'set_rank' && <div className="RpCharacterHistory-caption">
          {beforeRank.name ? <RankLabel rank={beforeRank}/> : 'Без ранга'} → {afterRank.name ? <RankLabel rank={afterRank}/> : 'Без ранга'}</div>}
        {(details.action === 'episode_reward' || details.action === 'topic_reward') && Array.isArray(details.grants) && details.grants.map((grant, index) => {
          const row = object(grant);
          return <div className="RpCharacterHistory-caption" key={index}>{text(row, 'name')}{Number(row.quantity) > 1 ? ' × ' + Number(row.quantity) : ''}</div>;
        })}
        {text(details, 'comment') && <p className="RpCharacterHistory-comment">{text(details, 'comment')}</p>}
      </div>
    </li>;
  }
}
