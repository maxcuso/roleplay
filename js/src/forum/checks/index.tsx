import app from 'flarum/forum/app';
import {extend, override} from 'flarum/common/extend';
import Post from 'flarum/common/models/Post';
import Button from 'flarum/common/components/Button';
import type ComposerState from 'flarum/forum/states/ComposerState';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import type ItemList from 'flarum/common/utils/ItemList';
import showModal from '../../common/showModal';
import {requestMessage} from '../../common/requestError';
import ModeratorMenu from '../episodes/ModeratorMenu';
import CheckApi from './CheckApi';
import CheckModal from './CheckModal';

const opening = new WeakSet<ComposerState>();

/** Окно открывается после чтения контекста, вставка использует исходный редактор и позицию. */
export async function openCheck(composer: ComposerState, type: 'request' | 'roll', requestId: string | null = null): Promise<void> {
  if (opening.has(composer)) return;
  const user = app.session.user, body = composer.body, editor = composer.editor, modal = app.modal.modal;
  if (!user || !editor || composer.bodyMatches('flarum/forum/components/EditPostComposer')) return;
  const discussion = body?.attrs?.discussion as Discussion | undefined;
  const api = new CheckApi(discussion ? Number(discussion.id()) : null, requestId);
  const [start] = editor.getSelectionRange();
  const current = (): boolean => composer.body === body && composer.editor === editor && app.session.user === user;
  opening.add(composer);
  try {
    const context = await api.context();
    const character = type === 'roll' && context.defaultCharacterId ? await api.character(context.defaultCharacterId) : undefined;
    if (!current() || app.modal.modal !== modal) return;
    showModal(CheckModal, {type, api, context, character, onInsert: code => {
      if (!current()) throw new Error('Редактор изменился');
      editor.insertBetween(start, start, '\n' + code + '\n', true);
    }});
  } catch (error) {if (current()) app.alerts.show({type: 'error'}, requestMessage(error, 'Не удалось открыть проверку. Попробуйте ещё раз'));}
  finally {opening.delete(composer); m.redraw();}
}

/** MagicDice сам выбирает стиль пользователя, рисует кубики и запускает свою анимацию.
 * Изолированный Post передаёт готовому contentHtml только два сохранённых d6,
 * поэтому позиции обычных бросков в исходном сообщении остаются прежними. */
export function renderNativeDice(post: Post, html: string): string {
  if (!html?.includes('data-check-first')) return html;
  const wrapper = document.createElement('div'); wrapper.innerHTML = html;
  for (const target of wrapper.querySelectorAll<HTMLElement>('.RpCheck-dice[data-check-first]')) {
    const first = Number(target.dataset.checkFirst), second = Number(target.dataset.checkSecond);
    if (![first, second].every(value => Number.isInteger(value) && value >= 1 && value <= 6)) continue;
    const nativePost = new Post({...post.data, attributes: {...post.data.attributes, contentHtml: '<p>1d6<br>1d6</p>', diceRolls: first + ',' + second}}, app.store);
    const native = document.createElement('div'); native.innerHTML = nativePost.contentHtml() ?? '';
    const dice = native.querySelectorAll('.roll-a-die');
    if (dice.length === 2) target.replaceChildren(dice[0], document.createTextNode(' + '), dice[1]);
  }
  return wrapper.innerHTML;
}

async function fromRequest(button: HTMLElement): Promise<void> {
  const panel = button.closest<HTMLElement>('[data-check-request]');
  const id = panel?.dataset.checkRequest, discussionId = panel?.dataset.discussion;
  if (!id || !discussionId || !app.session.user) return;
  try {
    const discussion = app.store.getById<Discussion>('discussions', discussionId) ?? await app.store.find<Discussion>('discussions', discussionId);
    if (!app.composer.composingReplyTo(discussion)) {
      await app.composer.load(() => import('flarum/forum/components/ReplyComposer'), {user: app.session.user, discussion});
    }
    await app.composer.show(); await app.composer.editorReady();
    if (app.composer.body?.attrs?.discussion !== discussion) return;
    await openCheck(app.composer, 'roll', id);
  } catch (error) {app.alerts.show({type: 'error'}, requestMessage(error, 'Не удалось открыть бросок. Попробуйте ещё раз'));}
}

function registerCheckTools(): void {
  extend(ModeratorMenu.prototype, 'actionItems', function (items: Mithril.Children[]) {
    const {composer, disabled} = this.attrs;
    if (composer.bodyMatches('flarum/forum/components/EditPostComposer')) return;
    items.push(m(Button, {type: 'button', className: 'Button Button--icon Button--link',
      icon: 'fas fa-clipboard-list', title: 'Запрос проверки', 'aria-label': 'Запрос проверки',
      disabled, onclick: () => void openCheck(composer, 'request')}));
  });
  extend('flarum/common/components/TextEditor', 'toolbarItems', function (items: ItemList<Mithril.Children>) {
    const composer = this.attrs.composer as ComposerState | undefined;
    if (!composer || composer.bodyMatches('flarum/forum/components/EditPostComposer')) return;
    items.add('roleplay-check-roll', m(Button, {type: 'button', className: 'Button Button--icon Button--link RpCheckRoll-button',
      icon: 'fas fa-dice', title: 'Бросок проверки', 'aria-label': 'Бросок проверки',
      disabled: this.disabled, onclick: () => void openCheck(composer, 'roll')}), -1000);
  });
}

app.initializers.add('maxcuso-roleplay-checks', () => {
  // Flarum 2 ещё не создал app.forum на этапе initializers.
  if (app.data.resources?.find(resource => resource.type === 'forums')?.attributes?.roleplayChecksAvailable !== true) return;
  registerCheckTools();
  override(Post.prototype, 'contentHtml', function (this: Post, original: () => string | null | undefined) {return renderNativeDice(this, original() ?? '');});
  const handlers = new WeakMap<HTMLElement, EventListener>();
  extend('flarum/forum/components/CommentPost', 'oncreate', function () {
    const element = this.element as HTMLElement;
    const handler: EventListener = event => {
      const button = (event.target as Element).closest<HTMLElement>('[data-roleplay-roll]');
      if (button && element.contains(button)) {event.preventDefault(); void fromRequest(button);}
    };
    handlers.set(element, handler); element.addEventListener('click', handler);
  });
  extend('flarum/forum/components/CommentPost', 'onremove', function () {
    const handler = handlers.get(this.element); if (handler) this.element.removeEventListener('click', handler);
  });
}, -100);
