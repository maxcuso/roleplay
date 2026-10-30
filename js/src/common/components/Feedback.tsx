import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import {messageKind, type MessageKind} from '../messageKind';
import {uiIcon} from '../uiIcons';

interface Attrs {
  message: string | string[];
  kind?: MessageKind;
  /** Постоянные состояния страницы не должны создавать всплывающее уведомление. */
  inline?: boolean;
  /** Спокойное условие доступности до попытки выполнить действие. */
  hint?: boolean;
  /** Компактное повторение запроса или обновление данных. Название остаётся в подсказке. */
  refresh?: {label: string; run: () => void; disabled?: boolean};
}

// Анкета и окно над ней могут показывать одно состояние. У одного сообщения
// остаётся одно уведомление, пока существует хотя бы одно место его показа.
const active = new Map<string, {id: number; owners: Set<Feedback>}>();

/** Только стандартные компоненты Flarum: Alert в форме или app.alerts для сбоев.
 * Показ выполняется в lifecycle, никогда не при каждом вызове view().
 */
export default class Feedback extends Component<Attrs> {
  private notification = '';
  private controls: Mithril.Children;

  private messages(): string[] {
    return [...new Set((Array.isArray(this.attrs.message) ? this.attrs.message : [this.attrs.message]).filter(Boolean))];
  }

  private kind(message: string): MessageKind {return this.attrs.kind ?? messageKind(message);}

  private errors(): string[] {
    return this.attrs.inline || this.attrs.hint ? [] : this.messages().filter(message => this.kind(message) !== 'input');
  }

  private sync(): void {
    const errors = this.errors(), key = JSON.stringify(errors);
    if (this.notification === key) return;
    this.release();
    if (!errors.length) return;
    this.notification = key;
    const existing = active.get(key);
    if (existing) existing.owners.add(this);
    else active.set(key, {
      id: app.alerts.show({type: 'error', role: 'alert', controls: this.controls}, errors.join('\n')),
      owners: new Set([this]),
    });
  }

  private release(): void {
    const entry = active.get(this.notification);
    if (entry) {
      entry.owners.delete(this);
      if (!entry.owners.size) {app.alerts.dismiss(entry.id); active.delete(this.notification);}
    }
    this.notification = '';
  }

  oncreate(): void {this.sync();}
  onupdate(): void {this.sync();}
  onremove(): void {this.release();}

  view(vnode: Mithril.Vnode<Attrs, this>): Mithril.Children {
    const refresh = this.attrs.refresh;
    this.controls = refresh ? [<Button type="button" className="Button Button--link Button--icon RpFeedback-refresh"
      icon={uiIcon('actions.refresh')} title={refresh.label} aria-label={refresh.label}
      disabled={refresh.disabled} onclick={() => this.attrs.refresh?.run()}/>] : vnode.children;
    const messages = this.messages();
    if (this.attrs.hint) return <p className="helpText">{messages.join(' ')}</p>;
    const warnings = messages.filter(message => this.attrs.inline || this.kind(message) === 'input');
    if (!warnings.length) return <span hidden/>;
    const type = this.attrs.inline && messages.some(message => this.kind(message) !== 'input') ? 'error' : 'warning';
    return <Alert type={type} role="alert" dismissible={false} controls={this.controls}>
      {warnings.length === 1 ? warnings[0] : <ul>{warnings.map(message => <li key={message}>{message}</li>)}</ul>}
    </Alert>;
  }
}
