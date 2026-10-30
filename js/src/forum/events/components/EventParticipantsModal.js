import app from 'flarum/common/app';
import Modal from 'flarum/common/components/Modal';

export default class EventParticipantsModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.event = this.attrs.event; this.busy = false; this.confirm = null; this.error = null; }
  className() { return 'QuestEventParticipantsModal'; }
  title() { return 'Участники события'; }
  async remove(user) {
    if (this.busy) return;
    this.busy = true;
    this.error = null;
    try {
      const response = await app.request({method:'DELETE', url:app.forum.attribute('apiUrl') + '/quest-events/' + this.event.id + '/participants/' + user.id,
        body:{revision:this.event.revision}, timeout:15000, errorHandler:()=>{}});
      this.update(response.data);
      this.confirm = null;
    } catch (error) {
      if (error.response?.data) this.update(error.response.data);
      this.confirm = null;
      this.error = error.response?.error || 'Не удалось получить ответ. Закрой окно и обнови список перед повторной попыткой.';
    } finally { this.busy = false; m.redraw(); }
  }
  update(event) { this.event = event; this.attrs.onupdated(event); }
  content() {
    const event = this.event;
    return m('.Modal-body', [
      m('h4', event.title),
      this.error ? m('p.QuestEventForm-error', {role:'alert'}, this.error) : null,
      event.status === 'completed' ? m('p.helpText', 'Состав завершённого события сохраняется для истории.') : null,
      !event.participants.length ? m('p', 'Участников пока нет.') : null,
      m('ul.QuestParticipants-list', event.participants.map(user => m('li', {key:user.id}, [
        m('a', {href:app.forum.attribute('baseUrl') + '/u/' + encodeURIComponent(user.slug ?? user.username)}, user.displayName),
        user.character ? m('span.QuestParticipants-character', user.character.name) : null,
        event.status !== 'completed' ? m('button.Button.Button--link', {disabled:this.busy, onclick:()=>{this.confirm=user.id;}}, 'Исключить') : null,
        this.confirm === user.id ? m('.QuestParticipants-confirm', [
          m('p', `Исключить ${user.displayName}? Слот освободится. Если минимум больше не набран, таймер будет отменён. Тема активного события сохранится.`),
          m('button.Button.Button--danger', {disabled:this.busy,onclick:()=>this.remove(user)}, this.busy ? 'Удаляем…' : 'Исключить участника'),
          m('button.Button', {disabled:this.busy,onclick:()=>{this.confirm=null;}}, 'Отмена'),
        ]) : null,
      ]))),
    ]);
  }
}
