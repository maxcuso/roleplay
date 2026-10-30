import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import { acquireEvent, releaseEvent, refreshEvent, changeParticipation } from '../utils/eventCardState';
import { cardStyle, countdown } from '../utils/eventAppearance';
import renderRewards from '../utils/renderRewards';

const icon = (name) => m(`i.icon.fas.fa-${name}`, { 'aria-hidden': 'true' });
const statuses = { recruiting: 'Набор', active: 'Идёт', completed: 'Завершено' };

export default class EmbeddedEventCard extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.state = acquireEvent(this.attrs.eventId);
    this.showAll = false;
    refreshEvent(this.state);
    this.timer = setInterval(() => {
      const state = this.state;
      // The server starts events; the browser only checks the result when due.
      if (state.data?.status === 'recruiting' && state.data.scheduledStartAt && Date.parse(state.data.scheduledStartAt) <= Date.now() && !state.busy && !state.loading) {
        refreshEvent(state);
      }
      m.redraw();
    }, 30000);
  }

  onremove() {
    clearInterval(this.timer);
    releaseEvent(this.state);
  }

  avatar(user, leader = false) {
    const name = user ? (user.character ? `${user.character.name} · ${user.displayName}` : user.displayName) : 'Ведущий не назначен';
    const href = user ? app.route('user', { username: user.slug ?? user.username ?? user.id }) : null;
    return m(`${href ? 'a' : 'span'}.QuestWidget-avatar`, { href: href || undefined, className: leader ? 'QuestWidget-avatar--leader' : '', title: name, role: href ? undefined : 'img', 'aria-label': href ? `Профиль ${name}` : name }, [
      user?.avatarUrl ? m('img', { src: user.avatarUrl, alt: '', loading: 'lazy' }) : m('span', { 'aria-hidden': 'true' }, user ? name.slice(0, 1).toUpperCase() : '?'),
      leader ? m('span.QuestWidget-crown', { title: 'Ведущий' }, icon('crown')) : null,
    ]);
  }

  action(event, disabled) {
    const state = this.state;
    const common = { type: 'button', disabled, className: 'QuestWidget-button' };
    if (event.status !== 'recruiting') {
      return m('button', { ...common, disabled: true }, event.status === 'active' ? 'Идёт событие' : 'Событие завершено');
    }
    if (event.canLeave) {
      return m('button', {
        ...common, className: 'QuestWidget-button QuestWidget-button--joined',
        'aria-label': 'Вы записаны. Отменить запись', title: 'Отменить запись',
        onclick: () => changeParticipation(state, true),
      }, state.busy ? 'Подождите…' : [
        m('span.QuestWidget-joinedLabel', [icon('check'), ' Вы записаны']),
        m('span.QuestWidget-leaveLabel', { 'aria-hidden': 'true' }, [icon('times'), ' Отменить']),
      ]);
    }
    if (event.isParticipating || !event.registrationOpen) {
      return m('button', { ...common, disabled: true }, event.isParticipating ? 'Вы записаны' : 'Свободных мест нет');
    }
    if (app.session.user && event.registrationOptions?.error) {
      return m('button', {...common, disabled: true, title: event.registrationOptions.error},
        [icon('user'), ' Нужен персонаж']);
    }
    return m('button', {
      ...common,
      onclick: () => app.session.user ? changeParticipation(state) : app.modal.show(() => import('flarum/forum/components/LogInModal')),
      title: app.session.user ? 'Записаться на событие' : 'Войти, чтобы записаться',
    }, state.busy ? 'Подождите…' : [icon('plus'), ' Записаться']);
  }

  view() {
    const state = this.state;
    const event = state.data;
    const disabled = state.busy || state.loading;
    const time = event && countdown(event);
    const participants = event?.participants || [];
    const visible = participants.slice(0, 4);
    const emptyCount = event?.status === 'recruiting' && event.participantCount < event.minParticipants
      ? Math.max(0, Math.min(4 - visible.length, event.minParticipants - event.participantCount)) : 0;
    const difficulty = event?.difficulty || 0;
    const difficultyRank = ['Обычный', 'Авантюрный', 'Героический', 'Эпический', 'Легендарный', 'Драконий'][difficulty-1];
    return m('section.QuestWidget', { style: cardStyle(event?.cardColor), 'aria-busy': String(disabled), 'aria-label': event?.title || 'Событие' }, [
      event ? [
        m('.QuestWidget-top', [
          m('.QuestWidget-symbol', { title: event.eventType === 'quest' ? `Ранг: ${difficultyRank}` : 'Форумное событие', 'aria-label': event.eventType === 'quest' ? `Квест, уровень ${event.difficulty}` : 'Форумное событие', role: 'img' }, '✦'),
          m('.QuestWidget-heading', [m('h3.QuestWidget-title', event.discussionId ? m('a.QuestWidget-titleLink', { href: app.route('discussion', { id: event.discussionId }) }, event.title) : event.title)]),
          m('.QuestWidget-meta', [
            m('span.QuestWidget-status', { className: `QuestWidget-status--${event.status}` }, [m('span', { 'aria-hidden': 'true' }, '●'), ' ', statuses[event.status] || event.status]),
            m('span.QuestWidget-count', { title: `Количество участников: ${event.minParticipants}–${event.maxParticipants}; записались: ${event.participantCount}` }, [icon('user-friends'), ` ${event.participantCount}/${event.maxParticipants}`]),
          ]),
        ]),
        event.description ? m('p.QuestWidget-description', { tabindex: 0, role: 'region', 'aria-label': 'Описание события' }, event.descriptionHtml === undefined ? event.description : m.trust(event.descriptionHtml)) : null,
        renderRewards(event),
        m('.QuestWidget-bottom', [
          m('.QuestWidget-leader', [this.avatar(event.leader, true), m('span', event.leader?.displayName || 'Не назначен')]),
          m('.QuestWidget-participants', { 'aria-label': 'Участники' }, [
            ...visible.map((user) => m('span', { key: `user-${user.id}` }, this.avatar(user))),
            ...Array.from({ length: emptyCount }, (_, i) => m('span.QuestWidget-empty', { key: `empty-${i}`, 'aria-hidden': 'true' })),
            ...(participants.length > 4 ? [m('button.QuestWidget-more', { key: 'more', type: 'button', 'aria-label': 'Показать всех участников', 'aria-expanded': String(this.showAll), onclick: () => { this.showAll = !this.showAll; } }, `+${participants.length - 4}`)] : []),
          ]),
          time ? m('time.QuestWidget-time', { datetime: event.scheduledStartAt, title: 'Старт: ' + new Date(event.scheduledStartAt).toLocaleString() }, [icon('clock'), ' ', time]) : null,
          this.action(event, disabled),
        ]),
        this.showAll ? m('.QuestWidget-allParticipants', participants.map((user) => m('span', { key: user.id }, [this.avatar(user), user.character?.name || user.displayName]))) : null,
      ] : state.loading ? m('p', { role: 'status' }, 'Загружаем событие…') : null,
      state.error ? m('p.QuestWidget-error', { role: 'alert' }, state.error) : null,
      m('button.QuestWidget-refresh', { type: 'button', title: 'Обновить данные', 'aria-label': 'Обновить данные события', disabled, onclick: () => refreshEvent(state) }, icon('sync-alt')),
    ]);
  }
}


