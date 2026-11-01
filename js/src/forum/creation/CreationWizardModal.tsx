import app from 'flarum/forum/app';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import CreationApi from './CreationApi';
import CreationState from './CreationState';
import CreationWizardForm from './CreationWizardForm';
import MechanicsEditApi from '../profile/MechanicsEditApi';
import type ProfileState from '../profile/ProfileState';
import showModal from '../../common/showModal';
import CreationConfirmationModal from './CreationConfirmationModal';
import {allowCreationOpening, creationRequiresLogin} from './creationAccess';

interface Attrs extends IInternalModalAttrs {profile?: ProfileState; wizard: CreationState; characterId: number | null}

/** Один мастер для создания и правки анкеты. Различаются только транспорт и завершение.
 * Создание сохраняет серверный черновик; правка применяет анкету только на последнем шаге.
 */
export default class CreationWizardModal extends Modal<Attrs> {
  private static opening: Promise<void> | null = null;

  /** Данные получаем до открытия: Modal сразу показывает готовый шаг, без окна загрузки. */
  static open(profile?: ProfileState): Promise<void> {
    if ((!profile || creationRequiresLogin()) && !allowCreationOpening()) return Promise.resolve();
    if (this.opening) return this.opening;
    if (!app.session.user) return Promise.resolve();
    this.opening = this.prepare(profile).finally(() => {this.opening = null;});
    return this.opening;
  }

  private static async prepare(profile?: ProfileState): Promise<void> {
    const characterId = profile?.selected ?? null;
    const user = app.session.user, route = m.route.get?.(), modal = app.modal.modal;
    const wizard = new CreationState(characterId === null ? new CreationApi() : new MechanicsEditApi(characterId), () => m.redraw());
    let opened = false;
    try {
      await wizard.load();
      // Медленный ответ не должен открывать окно после перехода или другого действия игрока.
      if (app.session.user !== user || m.route.get?.() !== route || app.modal.modal !== modal
        || (profile?.selected ?? null) !== characterId) return;
      if (!wizard.ready) {
        app.alerts.show({type: 'error'}, wizard.error || 'Не удалось открыть мастер создания персонажа. Попробуйте ещё раз');
        return;
      }
      showModal(CreationWizardModal, {profile, wizard, characterId});
      opened = true;
    } finally {
      if (!opened) wizard.dispose();
    }
  }

  wizard!: CreationState;
  characterId: number | null = null;
  disposed = false;
  private closing = false;
  private discard = false;
  private confirmationOpen = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.characterId = this.attrs.characterId;
    this.wizard = this.attrs.wizard;
    const modal = this.attrs.state.modal;
    if (modal) modal.attrs = {...modal.attrs, beforeRoleplayWizardClose: () => this.beforeClose()};
  }

  protected modalClassName(): string {return 'Modal--large RpWizardModal';}
  protected modalHeading(): ModalHeading {
    return this.characterId === null ? {label: 'Создание персонажа', purpose: 'create'}
      : {label: 'Редактирование персонажа', purpose: 'edit'};
  }

  content(): Mithril.Children {
    const editing = this.characterId !== null;
    return <div className="Modal-body"><CreationWizardForm state={this.wizard} loggedIn={!!app.session.user} embedded={true}
      submitLabel={editing ? 'Сохранить изменения' : 'Создать персонажа'} draftOnly={editing}
      notice={editing ? 'Изменения применятся после сохранения на последнем шаге. Награды и литературная тема сохранятся.' : undefined}
      profileUrl={(id: number) => this.profileUrl(id)} create={() => this.requestCreation()}/>
      {!editing && this.wizard.error && this.wizard.dirty && <Button className="Button Button--link RpWizard-discard"
        disabled={this.wizard.busy || this.wizard.creating} onclick={() => {this.discard = true; this.hide();}}>
        Закрыть без сохранения</Button>}
    </div>;
  }

  /** При ошибке сохранения оставляем окно и введённые данные на месте. */
  async beforeClose(): Promise<boolean> {
    if (this.closing || this.wizard.creating || this.wizard.uploading) return false;
    if (this.discard || this.characterId !== null || !this.wizard.ready) return true;
    this.closing = true;
    try {return !this.wizard.dirty && !this.wizard.busy || await this.wizard.flush();}
    finally {this.closing = false;}
  }

  private profileUrl(id: number): string {
    return app.route('user.roleplay', {username: app.session.user!.slug()}) + '?character=' + id;
  }

  /** Правка анкеты сохраняется сразу; новое создание сначала требует подтверждения. */
  private async requestCreation(): Promise<void> {
    if (this.characterId !== null) {await this.createCharacter(); return;}
    if (this.confirmationOpen || this.wizard.creating || this.wizard.uploading || this.wizard.conflict) return;
    this.confirmationOpen = true;
    this.wizard.attempted = false;
    const saved = await this.wizard.flush();
    this.wizard.attempted = saved;
    if (!saved || !this.wizard.view?.preview.valid || this.disposed) {
      this.confirmationOpen = false;
      m.redraw();
      return;
    }
    showModal(CreationConfirmationModal, {
      wizard: this.wizard,
      confirm: () => this.createCharacter(),
      leaveDraft: () => this.leaveDraft(),
      closed: () => {this.confirmationOpen = false;},
    }, true);
  }

  /** Снимаем оба связанных окна одним обновлением: скрытый мастер может не дать transitionend. */
  private closeWizard(): void {
    const state = this.attrs.state;
    if (state?.modal && (state.modal.componentClass as unknown) === CreationConfirmationModal
      && state.modal.attrs?.wizard === this.wizard) {
      state.close();
      if (state.modal && (state.modal.componentClass as unknown) === CreationWizardModal
        && state.modal.attrs?.wizard === this.wizard) state.close();
    } else this.hide();
  }

  private async leaveDraft(): Promise<void> {
    if (!await this.wizard.flush() || this.disposed) return;
    this.closeWizard();
  }

  async createCharacter(): Promise<void> {
    await this.wizard.create();
    if (this.wizard.createdId === null || this.disposed) return;
    this.closeWizard();
    if (this.attrs.profile) {
      if (this.attrs.profile.selected === this.characterId) await this.attrs.profile.select(this.characterId!);
    } else m.route.set(this.profileUrl(this.wizard.createdId));
  }

  async onbeforeremove(vnode: Mithril.VnodeDOM<Attrs, this>): Promise<void> {
    // Страхуем принудительное снятие окна при навигации; обычное закрытие уже сохранило ввод.
    if (this.characterId === null && !this.discard && this.wizard.dirty && !this.wizard.conflict) await this.wizard.flush();
    await super.onbeforeremove(vnode);
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    this.disposed = true;
    this.wizard.dispose();
    super.onremove(vnode);
  }
}
