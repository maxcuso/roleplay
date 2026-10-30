import EventParticipantsModal from './EventParticipantsModal';
import EventEditModal from './EventEditModal';
import app from 'flarum/common/app';
import Component from 'flarum/common/Component';

import renderRewards from '../utils/renderRewards';

export default class EventsPage extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.events = [];
    this.loading = false;
    this.error = null;
    this.editing = null;
    this.confirmDelete = null;
    this.deleting = false;
    this.nextBefore = null;
    if (app.forum.attribute('canManageQuestEvents')) this.loadEvents();
  }

  async loadEvents(more = false) {
    if (this.loading || this.deleting) return;
    this.loading = true;
    this.error = null;
    try {
      const response = await app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/quest-events',
        params: more ? { before: this.nextBefore } : {}, timeout: 15000, errorHandler: () => {} });
      this.events = more ? [...this.events, ...response.data] : response.data;
      this.nextBefore = response.meta.nextBefore;
      this.confirmDelete = null;
    } catch (error) {
      this.error = error.response?.error || 'Не удалось загрузить события. Проверь соединение и права доступа.';
    } finally { this.loading = false; m.redraw(); }
  }

  async deleteEvent(event) {
    if (this.deleting) return;
    this.deleting = true;
    this.error = null;
    try {
      await app.request({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/quest-events/' + event.id,
        body: { revision: event.revision }, timeout: 15000, errorHandler: () => {} });
      this.events = this.events.filter(item => item.id !== event.id);
      this.confirmDelete = null;
    } catch (error) {
      this.error = error.response?.error || 'Не удалось получить ответ. Обнови список: событие могло быть удалено.';
    } finally { this.deleting = false; m.redraw(); }
  }

  view() {
    if (!app.forum.attribute('canManageQuestEvents')) return m('.container.QuestEventsPage', [m('h1', 'Управление событиями'), m('p', 'Нужно разрешение на управление событиями.')]);
    return m('.container.QuestEventsPage', [
      m('h1', 'Управление событиями'),
      m('p.QuestEventsPage-intro', 'Изменения сохраняются в событии. Короткий BB-код в сообщениях остаётся прежним.'),
      this.error ? m('p.QuestEventForm-error', { role: 'alert' }, this.error) : null,
      m('button.Button', { type: 'button', disabled: this.loading || this.deleting, onclick: () => this.loadEvents() }, this.loading ? 'Загрузка…' : 'Обновить список'),
      !this.loading && !this.events.length ? m('p', 'Событий пока нет.') : null,
      m('.QuestEventsTable-scroll', [m('table.QuestEventsTable', [
        m('thead', m('tr', ['Название', 'Статус', 'Дата начала', 'Ведущий', 'Участники', 'Минимум', 'Максимум', 'Награда', 'Действия'].map(label => m('th', { scope: 'col' }, label)))),
        m('tbody', this.events.map(event => this.eventRow(event))),
      ])]),
      this.nextBefore ? m('button.Button', { type: 'button', disabled: this.loading || this.deleting, onclick: () => this.loadEvents(true) }, 'Показать ещё') : null,
    ]);
  }

  async editEvent(event) {
    this.editing = event;
    this.confirmDelete = null;
    try {
      await app.modal.show(EventEditModal, {
        event,
        onclose: () => { this.editing = null; },
        onsaved: updated => {
          this.events = this.events.map(item => item.id === updated.id ? updated : item);
          this.error = null;
        },
      });
    } catch (error) {
      this.editing = null;
      this.error = 'Не удалось открыть редактор. Обнови страницу и попробуй снова.';
    }
  }

  updateEvent(updated) {
    this.events = this.events.map(event => event.id === updated.id ? updated : event);
  }

  async retryStart(event) {
    if (this.retrying) return;
    this.retrying = event.id;
    try {
      const response = await app.request({method:'POST', url:app.forum.attribute('apiUrl') + '/quest-events/' + event.id + '/retry-start',
        body:{revision:event.revision}, timeout:15000, errorHandler:()=>{}});
      this.updateEvent(response.data);
      app.alerts.show({type:'success'}, response.data.status === 'active' ? 'Событие запущено.' : 'Условия запуска проверены. Набор продолжается.');
    } catch (error) {
      if (error.response?.data) this.updateEvent(error.response.data);
      app.alerts.show({type:'warning'}, error.response?.error || 'Ответ не получен. Обнови список перед повторной попыткой.');
    } finally { this.retrying = null; m.redraw(); }
  }

  eventRow(event) {
    const status = { recruiting: 'Набор', active: 'Идёт', completed: 'Завершено' };
    const start = event.scheduledStartAt || event.preferredStartAt;
    return m('tr', { key: event.id }, [
      m('td.QuestEventsTable-title', event.discussionId ? m('a', { href: app.forum.attribute('baseUrl') + '/d/' + event.discussionId }, event.title) : event.title),
      m('td', [m('span.QuestEventsTable-status', { className: `QuestEventsTable-status--${event.status}` }, status[event.status] || event.status),
        event.startError ? m('p.QuestEventsTable-startError', {role:'status'}, [event.startError, event.startFailedAt ? m('small', 'С ' + new Date(event.startFailedAt).toLocaleString()) : null]) : null,
        event.sourcePublished === false ? m('p.helpText', 'Источник скрыт, не одобрен или удалён') : null]),
      m('td', start ? m('time', { datetime: start }, new Date(start).toLocaleString()) : '—'),
      m('td', event.leader?.displayName || '—'),
      m('td', m('button.Button.Button--link', { title: 'Управление участниками', onclick: () => app.modal.show(EventParticipantsModal, {event, onupdated: updated => this.updateEvent(updated)}) }, String(event.participantCount))),
      m('td', event.minParticipants),
      m('td', event.maxParticipants),
      m('td', renderRewards(event) || '—'),
      m('td', [
        event.canRetryStart ? m('button.Button', {disabled: !!this.retrying, onclick: () => this.retryStart(event)}, this.retrying === event.id ? 'Запускаем…' : 'Повторить запуск') : null,
        m('.QuestEventForm-actions', [
          m('button.Button.Button--primary', { type: 'button', disabled: !!this.editing || this.deleting, onclick: () => this.editEvent(event) }, 'Изменить'),
          m('button.Button', { type: 'button', disabled: !!this.editing || this.deleting, onclick: () => { this.confirmDelete = event.id; } }, 'Удалить'),
        ]),
        this.confirmDelete === event.id ? m('.QuestEventsPage-confirm', { role: 'alert' }, [
          m('p', `Удалить «${event.title}» и записи участников? Созданная тема сохранится.`),
          m('button.Button.Button--danger', { disabled: this.deleting, onclick: () => this.deleteEvent(event) }, 'Удалить безвозвратно'),
          m('button.Button', { disabled: this.deleting, onclick: () => { this.confirmDelete = null; } }, 'Отмена'),
        ]) : null,
      ]),
    ]);
  }

}





