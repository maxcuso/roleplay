import app from 'flarum/common/app';
import EventForm from './EventForm';

export default class EventEditForm extends EventForm {
  oninit(vnode) {
    super.oninit(vnode);
    this.isManagement = true;
    this.lockTiming = this.attrs.event.status !== 'recruiting';
    const event = this.attrs.event;
    let start = '';
    if (event.preferredStartAt) {
      const date = new Date(event.preferredStartAt);
      start = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19);
    }
    this.values = {
      title: event.title, description: event.description || '', minimum: String(event.minParticipants), maximum: String(event.maxParticipants),
      delay: String(event.startDelayHours), leader: event.leader ? String(event.leader.id) : '', start, draft: 'keep',
      eventType: event.eventType, difficulty: String(event.difficulty || 3), customColor: event.cardColor,
      rewardExperience: String(event.reward?.experience || 0), rewardGold: String(event.reward?.gold || 0), rewardItems: String(event.reward?.items || 0),
    };
    this.leaderQuery = event.leader?.username || '';
    this.saving = false;
  }

  // Reuse the fields without mounting a toolbar dropdown on this page.
  oncreate() { if (!this.lockTiming) this.loadDrafts(); }
  view() { return this.getMenu(); }
  close() { if (!this.saving) this.attrs.oncancel(); }

  async insert(event) {
    event.preventDefault();
    event.stopPropagation();
    if (this.saving) return;
    const v = this.values;
    if (this.leaderQuery.trim() && !v.leader) {
      this.error = 'Выбери ведущего из подсказок или очисти поле.';
      return;
    }
    if (v.draft !== 'keep' && v.draft && !this.drafts.some(draft => String(draft.id) === v.draft && !draft.unavailableReason)) {
      this.error = 'Выбранный черновик недоступен.';
      return;
    }
    this.saving = true;
    this.error = null;
    try {
      const response = await app.request({
        method: 'PATCH', url: app.forum.attribute('apiUrl') + '/quest-events/' + this.attrs.event.id,
        timeout: 15000, errorHandler: () => {},
        body: {
          revision: this.attrs.event.revision, title: v.title.trim(), description: v.description.trim() || null,
          minParticipants: Number(v.minimum), maxParticipants: Number(v.maximum), startDelayHours: Number(v.delay),
          preferredStartAt: v.start ? new Date(v.start).toISOString().replace(/\.\d{3}Z$/, '+00:00') : null,
          leaderId: v.leader ? Number(v.leader) : null, eventType: v.eventType,
          difficulty: v.eventType === 'quest' ? Number(v.difficulty) : null,
          reward: {
            experience: v.eventType === 'quest' ? Number(v.rewardExperience) : 0,
            gold: v.eventType === 'quest' ? Number(v.rewardGold) : 0,
            items: v.eventType === 'quest' ? Number(v.rewardItems) : 0,
          },
          customColor: v.eventType === 'forum' ? v.customColor : null,
          draftAction: v.draft === 'keep' ? 'keep' : v.draft ? 'select' : 'clear', draftId: v.draft && v.draft !== 'keep' ? Number(v.draft) : null,
        },
      });
      this.attrs.onsaved(response.data);
    } catch (error) {
      this.error = error.response?.error || 'Не удалось сохранить. Обнови список перед повторной попыткой: сервер мог уже принять изменения.';
    } finally { this.saving = false; m.redraw(); }
  }
}
