import Feedback from '../../common/components/Feedback';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import {object, text} from '../../common/catalogData';
import type ProfileState from './ProfileState';

interface Attrs extends IInternalModalAttrs {profile: ProfileState}
/** Исправляет только личность; раса, навыки, перки и стартовые выдачи здесь не редактируются. */
export default class IdentityCorrectionModal extends Modal<Attrs> {
  firstName = ''; lastName = ''; age = '';
  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    const character = object(this.attrs.profile.view?.snapshot.character);
    this.firstName = text(character, 'first_name'); this.lastName = text(character, 'last_name'); this.age = text(character, 'age');
  }
  protected modalClassName(): string {return 'RpReviewModal';}
  protected modalHeading(): ModalHeading { return {label: 'Исправление данных персонажа', purpose: 'edit'}; }
  content(): Mithril.Children {
    const state = this.attrs.profile;
    return <form className="Modal-body RpReview-form" oninput={() => state.clearInputError()} onchange={() => state.clearInputError()}
      onsubmit={(event: Event) => {event.preventDefault(); void this.save();}}>
      <p>После сохранения отправь техническую часть на повторную проверку.</p>
      <label>Имя<input className="FormControl" required maxlength={100} value={this.firstName} disabled={state.actionsBlocked}
        oninput={(event: Event) => {this.firstName = (event.target as HTMLInputElement).value;}}/></label>
      <label>Фамилия<input className="FormControl" maxlength={100} value={this.lastName} disabled={state.actionsBlocked}
        oninput={(event: Event) => {this.lastName = (event.target as HTMLInputElement).value;}}/></label>
      <label>Возраст<input className="FormControl" type="number" required min={0} max={1000000} step={1}
        value={this.age} disabled={state.actionsBlocked}
        oninput={(event: Event) => {this.age = (event.target as HTMLInputElement).value;}}/></label>
      {state.actionError && <Feedback message={state.actionError}/>}
      {state.retryAvailable ? <Button type="button" className="Button Button--primary" loading={state.changing}
        disabled={state.changing} onclick={async () => {if (await state.retryOperation()) this.hide();}}>Повторить действие</Button>
        : <Button type="submit" className="Button Button--primary" loading={state.changing}
          disabled={state.actionsBlocked || !this.firstName.trim() || !this.age}>Сохранить изменения</Button>}
    </form>;
  }
  private async save(): Promise<void> {
    if (await this.attrs.profile.correctIdentity(this.firstName.trim(), this.lastName.trim(), Number(this.age))) this.hide();
  }
}
