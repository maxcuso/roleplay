import Modal, {type IInternalModalAttrs} from 'flarum/common/components/Modal';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {text} from '../../common/catalogData';
import {uiIcon} from '../../common/uiIcons';
import {reviewParts, reviewStatus} from './reviewPresentation';
import type {ReviewPart, ReviewView} from './types';

interface Attrs extends IInternalModalAttrs {events: ReviewView['history']}

/** Используем уже загруженную историю проверки, не запрашивая полный журнал персонажа. */
export default class ReviewHistoryModal extends Modal<Attrs> {
  className(): string {return 'Modal--small RpHistoryModal RpReviewHistoryModal';}
  title(): Mithril.Children {return <><Icon name={uiIcon('actions.history')}/> История проверки</>;}
  content(): Mithril.Children {
    return <div className="Modal-body"><div className="RpHistory">
      {!this.attrs.events.length ? <p>История пока пуста.</p> : <ul>{this.attrs.events.map(event => {
        const details = event.details, part = text(details, 'part') as ReviewPart;
        const label = event.type === 'mechanics_updated' ? 'Техническая часть изменена и отправлена на проверку'
          : event.type === 'identity_corrected' ? 'Данные персонажа исправлены'
            : (reviewParts[part] ?? '') + ': ' + reviewStatus(part, text(details, 'after')).label;
        return <li key={event.id}><strong>{label}</strong><small>{event.created_at}</small>
          {text(details, 'comment') && <p>{text(details, 'comment')}</p>}</li>;
      })}</ul>}
    </div></div>;
  }
}
