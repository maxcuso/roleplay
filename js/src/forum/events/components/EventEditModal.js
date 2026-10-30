import Modal from 'flarum/common/components/Modal';
import EventEditForm from './EventEditForm';

export default class EventEditModal extends Modal {
  className() { return 'QuestEventEditModal Modal--large'; }
  title() { return 'Редактирование события'; }
  onready() { this.$('input[name="title"]').trigger('focus'); }
  content() {
    return m(EventEditForm, {
      event: this.attrs.event,
      oncancel: () => this.hide(),
      onsaved: updated => {
        this.attrs.onsaved(updated);
        if (!this.removed) this.hide();
      },
    });
  }
  onremove(vnode) {
    this.removed = true;
    this.attrs.onclose?.();
    super.onremove?.(vnode);
  }
}
