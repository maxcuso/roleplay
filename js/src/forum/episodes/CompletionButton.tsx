import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type ComposerState from 'flarum/forum/states/ComposerState';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import showModal from '../../common/showModal';
import {requestMessage} from '../../common/requestError';
import {uiIcon} from '../../common/uiIcons';
import EpisodeCompletionApi from './EpisodeCompletionApi';
import EpisodeCompletionModal from './EpisodeCompletionModal';

interface Attrs {composer: ComposerState; discussion: Discussion; disabled?: boolean; onInserted?: () => void}

export default class CompletionButton extends Component<Attrs> {
  private opening = false;
  private disposed = false;
  onremove(): void {this.disposed = true;}
  view(): Mithril.Children {
    return <Button type="button" className="Button Button--icon Button--link" icon={uiIcon('extension')}
      title="Завершение эпизода" aria-label="Завершение эпизода" disabled={this.attrs.disabled || this.opening}
      loading={this.opening} onclick={() => void this.open()}/>;
  }
  private async open(): Promise<void> {
    if (this.opening || this.attrs.disabled) return;
    const composer = this.attrs.composer, editor = composer.editor, body = composer.body;
    const user = app.session.user, modal = app.modal.modal;
    if (!editor || !user) return;
    const [start] = editor.getSelectionRange();
    const api = new EpisodeCompletionApi(Number(this.attrs.discussion.id()));
    const current = (): boolean => !this.disposed && composer.body === body && composer.editor === editor && app.session.user === user;
    this.opening = true; m.redraw();
    try {
      const context = await api.context();
      if (!current() || app.modal.modal !== modal) return;
      showModal(EpisodeCompletionModal, {context, source: api, onInsert: async payload => {
        if (!current()) throw new Error('Редактор изменился');
        const code = await api.prepare(payload);
        if (!current()) throw new Error('Редактор изменился');
        editor.insertBetween(start, start, '\n' + code + '\n', true);
        this.attrs.onInserted?.();
      }});
    } catch (error) {
      if (current()) app.alerts.show({type: 'error'}, requestMessage(error, 'Не удалось открыть завершение эпизода. Попробуйте ещё раз'));
    } finally {if (!this.disposed) {this.opening = false; m.redraw();}}
  }
}
