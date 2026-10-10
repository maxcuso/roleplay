import Modal, {type IInternalModalAttrs} from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type ProfileState from './ProfileState';
import type {ReviewPart, ReviewAction} from './types';

interface Attrs extends IInternalModalAttrs {
  profile: ProfileState; part: ReviewPart; action: Extract<ReviewAction, 'approve' | 'request_changes'>;
}
/** При возврате замечание обязательно и на клиенте, и на сервере. */
export default class ReviewDecisionModal extends Modal<Attrs> {
  comment = '';
  className(): string {return 'RpReviewModal';}
  title(): string {
    return (this.attrs.action === 'approve' ? 'Одобрить' : 'Вернуть на исправление') + ' '
      + (this.attrs.part === 'mechanics' ? 'техническую часть' : 'литературную часть');
  }
  content(): Mithril.Children {
    const state = this.attrs.profile;
    return <form className="Modal-body RpReview-form" onsubmit={(event: Event) => {event.preventDefault(); void this.save();}}>
      <p>{this.attrs.action === 'approve' ? 'Подтверди проверку актуальной версии анкеты.' : 'Укажи, что игроку нужно исправить.'}</p>
      <label>Замечания{this.attrs.action === 'request_changes' ? ' (обязательно)' : ''}
        <textarea className="FormControl" value={this.comment} maxlength={4000}
          disabled={state.actionsBlocked} required={this.attrs.action === 'request_changes'}
          oninput={(event: Event) => {this.comment = (event.target as HTMLTextAreaElement).value;}}/>
      </label>
      {state.actionError && <p role="alert">{state.actionError}</p>}
      {state.retryAvailable ? <Button type="button" className="Button Button--primary" loading={state.changing}
        disabled={state.changing} onclick={async () => {if (await state.retryOperation()) this.hide();}}>Повторить действие</Button>
        : <Button type="submit" className="Button Button--primary" loading={state.changing} disabled={state.actionsBlocked
          || this.attrs.action === 'request_changes' && !this.comment.trim()}>Подтвердить</Button>}
    </form>;
  }
  private async save(): Promise<void> {
    if (await this.attrs.profile.changeReview(this.attrs.part, this.attrs.action, this.comment.trim())) this.hide();
  }
}
