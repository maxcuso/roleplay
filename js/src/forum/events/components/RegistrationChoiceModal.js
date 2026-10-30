import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Icon from 'flarum/common/components/Icon';

/** Движок показывает варианты дополнения; сущности и правила выбора приходят с сервера. */
export default class RegistrationChoiceModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.selected = null;
    this.busy = false;
  }

  className() { return 'QuestRegistrationChoiceModal'; }
  title() { return [m(Icon, {name: 'fas fa-user'}), ' ', this.attrs.options.title]; }

  async submit(event) {
    event.preventDefault();
    if (this.busy || this.selected === null) return;
    this.busy = true;
    m.redraw();
    try {
      const joined = await this.attrs.onselect({[this.attrs.options.field]: this.selected});
      // Ответ старого запроса не должен закрыть другое окно, открытое за время записи.
      if (joined && app.modal.modal?.attrs?.onselect === this.attrs.onselect) this.hide();
    } finally {
      this.busy = false;
      m.redraw();
    }
  }

  content() {
    return m('form.Modal-body', {onsubmit: event => this.submit(event)}, [
      m('p.QuestRegistration-event', this.attrs.eventTitle),
      m('.QuestRegistration-choices', {role: 'radiogroup', 'aria-label': this.attrs.options.title}, this.attrs.options.choices.map(choice =>
        m('button.QuestRegistration-choice', {
          key: choice.id, type: 'button', role: 'radio', 'aria-checked': this.selected === choice.id,
          className: this.selected === choice.id ? 'is-selected' : '', disabled: this.busy,
          onclick: () => { this.selected = choice.id; },
        }, [m('span', choice.label), m(Icon, {name: this.selected === choice.id ? 'fas fa-check-circle' : 'far fa-circle'})])
      )),
      m('.QuestRegistration-actions', [
        m('button.Button', {type: 'button', disabled: this.busy, onclick: () => this.hide()}, 'Отмена'),
        m('button.Button.Button--primary', {type: 'submit', disabled: this.busy || this.selected === null}, this.busy ? 'Записываем…' : 'Записаться'),
      ]),
    ]);
  }
}
