import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type ComposerState from 'flarum/forum/states/ComposerState';
import type Mithril from 'mithril';
import showModal from '../../../common/showModal';
import EventCreateModal from './EventCreateModal';
import {uiIcon} from '../../../common/uiIcons';

interface Attrs {composer: ComposerState; disabled?: boolean; onInserted?: () => void}

export default class EventCreateButton extends Component<Attrs> {
  view(): Mithril.Children {
    return <Button type="button" className="Button Button--icon Button--link" icon={uiIcon('editor.event')} disabled={this.attrs.disabled}
      title="Создание события" aria-label="Создание события" onclick={() => this.open()}/>;
  }
  private open(): void {
    const composer = this.attrs.composer, editor = composer.editor, body = composer.body;
    if (!editor || this.attrs.disabled) return;
    const [start] = editor.getSelectionRange();
    showModal(EventCreateModal, {oninsert: code => {
      if (composer.editor !== editor || composer.body !== body) throw new Error('Редактор изменился. Откройте создание события заново');
      editor.insertBetween(start, start, '\n' + code + '\n', true);
      this.attrs.onInserted?.();
    }});
  }
}
