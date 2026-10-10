import app from 'flarum/forum/app';
import Modal, {type IInternalModalAttrs} from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import CreationApi from './CreationApi';
import CreationState from './CreationState';
import CreationWizardForm from './CreationWizardForm';
import MechanicsEditApi from '../profile/MechanicsEditApi';
import type ProfileState from '../profile/ProfileState';
import {uiIcon} from '../../common/uiIcons';

interface Attrs extends IInternalModalAttrs {profile?: ProfileState}

/** Один мастер для создания и правки анкеты. Различаются только транспорт и завершение.
 * Создание сохраняет серверный черновик; правка применяет анкету только на последнем шаге.
 */
export default class CreationWizardModal extends Modal<Attrs> {
  wizard!: CreationState;
  characterId: number | null = null;
  disposed = false;
  private closing = false;
  private discard = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.characterId = this.attrs.profile?.selected ?? null;
    this.wizard = new CreationState(this.characterId === null ? new CreationApi() : new MechanicsEditApi(this.characterId), () => m.redraw());
    const modal = this.attrs.state.modal;
    if (modal) modal.attrs = {...modal.attrs, beforeRoleplayWizardClose: () => this.beforeClose()};
    if (app.session.user) void this.wizard.load();
  }

  className(): string {return 'Modal--large RpWizardModal';}
  title(): Mithril.Children {
    return <><Icon name={uiIcon('wizard.title')}/> {this.characterId === null ? 'Создание персонажа' : 'Редактирование персонажа'}</>;
  }

  content(): Mithril.Children {
    const editing = this.characterId !== null;
    return <div className="Modal-body"><CreationWizardForm state={this.wizard} loggedIn={!!app.session.user} embedded={true}
      submitLabel={editing ? 'Сохранить изменения' : 'Создать персонажа'} draftOnly={editing}
      notice={editing ? 'Изменения применятся после сохранения на последнем шаге. Награды и литературная тема сохранятся.' : undefined}
      profileUrl={(id: number) => this.profileUrl(id)} create={() => this.createCharacter()}/>
      {!editing && this.wizard.error && this.wizard.dirty && <Button className="Button Button--link RpWizard-discard"
        disabled={this.wizard.busy || this.wizard.creating} onclick={() => {this.discard = true; this.hide();}}>
        Закрыть без сохранения</Button>}
    </div>;
  }

  /** При ошибке сохранения оставляем окно и введённые данные на месте. */
  async beforeClose(): Promise<boolean> {
    if (this.closing || this.wizard.creating) return false;
    if (this.discard || this.characterId !== null || !this.wizard.ready) return true;
    this.closing = true;
    try {return !this.wizard.dirty && !this.wizard.busy || await this.wizard.flush();}
    finally {this.closing = false;}
  }

  private profileUrl(id: number): string {
    return app.route('user.roleplay', {username: app.session.user!.slug()}) + '?character=' + id;
  }

  async createCharacter(): Promise<void> {
    await this.wizard.create();
    if (this.wizard.createdId === null || this.disposed) return;
    this.hide();
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
