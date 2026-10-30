import Feedback from '../../common/components/Feedback';
import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import {object} from '../../common/catalogData';
import {uiIcon} from '../../common/uiIcons';
import showModal from '../../common/showModal';
import ReviewDecisionModal from './ReviewDecisionModal';
import IdentityCorrectionModal from './IdentityCorrectionModal';
import CreationWizardModal from '../creation/CreationWizardModal';
import type ProfileState from './ProfileState';
import type {ReviewPart} from './types';
import ReviewHistoryModal from './ReviewHistoryModal';
import {reviewParts, reviewStatus} from './reviewPresentation';

interface Attrs {state: ProfileState}

/** Два независимых решения в одной панели. Доступность действий задаёт только сервер. */
export default class ReviewPanel extends Component<Attrs> {
  view(): Mithril.Children {
    const state = this.attrs.state, view = state.view, review = view?.review;
    if (!view || !review || object(view.snapshot.character).status !== 'creating') return null;
    const approvedCount = Number(review.mechanics_status === 'approved') + Number(review.literature_status === 'approved');
    const discussionId = Number(object(view.snapshot.character).discussion_id);
    const topicLink = discussionId > 0 && review.topic_available
      ? <LinkButton className="RpReview-topicLink" href={app.forum.attribute<string>('baseUrl') + '/d/' + discussionId}>своей теме</LinkButton>
      : 'своей теме';
    return <section className="RpReview" aria-label="Проверка анкеты">
      <header className="RpReview-heading">
        <span className="RpReview-icon"><Icon name={uiIcon('review.application')}/></span>
        <div><strong>Проверка анкеты</strong><small>Персонаж станет активным после одобрения обеих частей.</small></div>
        <div className="RpReview-tools">
          <Button className="Button Button--icon RpReview-historyButton" icon={uiIcon('actions.history')}
            title="История проверки" aria-label="История проверки"
            onclick={() => showModal(ReviewHistoryModal, {events: review.history})}/>
          <span className="RpReview-progress" title="Количество одобренных частей">{approvedCount}/2</span>
        </div>
      </header>
      {!review.topic_available && <Feedback message="Литературная тема недоступна. Обратитесь к администратору" kind="input"/>}
      <div className="RpReview-parts">{(['mechanics', 'literature'] as ReviewPart[]).map(part => {
        const status = part === 'mechanics' ? review.mechanics_status : review.literature_status;
        const presentation = reviewStatus(part, status);
        return <div key={part} className={'RpReview-part is-' + status}>
          <div className="RpReview-partHeading">
            <Icon name={uiIcon(part === 'mechanics' ? 'review.mechanics' : 'profile.literature')}/>
            <strong>{reviewParts[part]}</strong>
            <span className="RpReview-status"><Icon name={uiIcon(presentation.icon)}/>{presentation.label}</span>
          </div>
          <small className="RpReview-caption">
            {/* Невидимые пояснения занимают место по самой длинной версии при текущей ширине.
              * Они не читаются скринридером и не содержат дублирующей ссылки для фокуса. */}
            {part === 'literature'
              ? <span className="RpReview-captionSizing" aria-hidden="true">Заполни шаблон в <strong>своей теме</strong>. Когда закончишь — нажми кнопку ниже.</span>
              : <><span className="RpReview-captionSizing" aria-hidden="true">Проверка характеристик и выборов персонажа.</span>
                <span className="RpReview-captionSizing" aria-hidden="true">Исправь замечания и отправь анкету повторно.</span></>}
            <span>{part === 'literature'
            ? status === 'approved' ? 'Литературная часть одобрена.'
              : status === 'review' ? 'Анкета отправлена на проверку'
                : <>Заполни шаблон в {topicLink}. Когда закончишь — нажми кнопку ниже.</>
            : status === 'changes_requested' ? 'Исправь замечания и отправь анкету повторно.'
              : status === 'approved' ? 'Техническая часть одобрена.' : 'Проверка характеристик и выборов персонажа.'}</span>
          </small>
          <div className="RpReview-actions">
            {part === 'mechanics' && review.permissions.can_edit_mechanics && <Button className="Button RpReview-editButton"
              icon={uiIcon('actions.edit')} disabled={state.actionsBlocked}
              onclick={() => void CreationWizardModal.open(state)}>Редактировать</Button>}
            {part === 'literature' && review.permissions.can_submit_literature && <Button className="Button RpReview-editButton"
              icon={uiIcon('review.submit')} disabled={state.actionsBlocked} loading={state.changing}
              onclick={() => void state.changeReview('literature', 'submit')}>На проверку</Button>}
            {part === 'literature' && review.permissions.can_withdraw_literature && <Button className="Button RpReview-editButton"
              icon={uiIcon('review.return')} disabled={state.actionsBlocked} loading={state.changing}
              onclick={() => void state.changeReview('literature', 'withdraw')}>Отозвать</Button>}
            {part === 'mechanics' && !review.permissions.can_edit_mechanics && review.permissions.can_edit_identity && <Button
              className="Button" icon={uiIcon('actions.edit')} disabled={state.actionsBlocked}
              onclick={() => showModal(IdentityCorrectionModal, {profile: state})}>Исправить имя и возраст</Button>}
            {part === 'mechanics' && review.permissions.can_submit_mechanics && <Button className="Button Button--primary"
              icon={uiIcon('review.submit')} disabled={state.actionsBlocked}
              onclick={() => void state.changeReview(part, 'submit')}>Отправить на проверку</Button>}
            {part === 'mechanics' && review.permissions.can_moderate && status === 'review' && <Button className="Button Button--primary"
              icon={uiIcon('actions.select')} disabled={state.actionsBlocked}
              onclick={() => showModal(ReviewDecisionModal, {profile: state, part, action: 'approve'})}>Одобрить</Button>}
            {part === 'mechanics' && review.permissions.can_moderate && ['review', 'approved'].includes(status) && <Button
              className="Button" icon={uiIcon('review.return')} disabled={state.actionsBlocked}
              onclick={() => showModal(ReviewDecisionModal, {profile: state, part, action: 'request_changes'})}>
              Вернуть на исправление</Button>}
          </div>
        </div>;
      })}</div>
    </section>;
  }
}
