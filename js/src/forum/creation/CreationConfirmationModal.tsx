import Feedback from '../../common/components/Feedback';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type CreationState from './CreationState';

interface Attrs extends IInternalModalAttrs {
  wizard: CreationState;
  confirm: () => Promise<void>;
  leaveDraft: () => Promise<void>;
  closed: () => void;
}

/** До явного подтверждения ни персонаж, ни литературная тема не создаются. */
export default class CreationConfirmationModal extends Modal<Attrs> {
  private pending = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    const modal = this.attrs.state.modal;
    if (modal) modal.attrs = {...modal.attrs, beforeRoleplayWizardClose: async () => !this.blocked()};
  }

  protected modalClassName(): string {return 'RpCreationConfirmModal';}
  protected modalHeading(): ModalHeading {return {label: 'Подтверждение создания', purpose: 'create'};}

  private blocked(): boolean {
    const wizard = this.attrs.wizard;
    return this.pending || wizard.busy || wizard.creating || wizard.uploading;
  }

  private async act(action: () => Promise<void>): Promise<void> {
    if (this.blocked()) return;
    this.pending = true;
    m.redraw();
    try {await action();}
    finally {this.pending = false; m.redraw();}
  }

  content(): Mithril.Children {
    const wizard = this.attrs.wizard;
    const payload = wizard.view!.draft.payload;
    const name = [payload.first_name, payload.last_name].filter(Boolean).join(' ');
    return <div className="Modal-body">
      <p>После подтверждения на форуме появится тема с именем персонажа <strong>{name}</strong>.
        Художественная анкета в ней пока будет пустой</p>
      <p>Если вы хотите сразу разместить готовую анкету, выберите «Оставить черновик».
        Сначала подготовьте художественную часть в отдельной теме. Когда всё будет готово,
        вернитесь в мастер, подтвердите создание и перенесите BB-код анкеты в созданную тему</p>
      <p className="helpText">Ваш выбор в мастере сохранён — к нему можно вернуться позже</p>
      {wizard.error && <Feedback message={wizard.error}/>}
      <div className="RpCreationConfirm-actions">
        <Button className="Button" disabled={this.blocked()} onclick={() => void this.act(this.attrs.leaveDraft)}>
          Оставить черновик</Button>
        <Button className="Button Button--primary" loading={wizard.creating} disabled={this.blocked() || wizard.conflict}
          onclick={() => void this.act(this.attrs.confirm)}>Создать персонажа</Button>
      </div>
    </div>;
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    this.attrs.closed();
    super.onremove(vnode);
  }
}
