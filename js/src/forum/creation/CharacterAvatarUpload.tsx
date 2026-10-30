import Feedback from '../../common/components/Feedback';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import characterAvatarUrl from '../../common/character/characterAvatarUrl';
import {requestMessage} from '../../common/requestError';
import {uiIcon} from '../../common/uiIcons';
import type CreationState from './CreationState';

/** Загрузка отделена от сохранения анкеты. Кнопки перехода ждут её завершения. */
export default class CharacterAvatarUpload extends Component<{state: CreationState}> {
  input?: HTMLInputElement;
  button?: HTMLButtonElement;
  error = '';
  private menu: HTMLElement | null = null;
  private menuListeners: Array<[EventTarget, string, EventListener, boolean]> = [];

  /** Меню вне прокрутки, но внутри Modal: так сохраняется фокус клавиатуры Flarum. */
  private openMenu(anchor: HTMLElement): void {
    const rect = anchor.getBoundingClientRect(), host = document.createElement('div');
    const modal = anchor.closest<HTMLElement>('.Modal') ?? document.body;
    const origin = modal === document.body ? {left: 0, top: 0} : modal.getBoundingClientRect();
    host.className = 'RpWizard-avatarMenu Dropdown dropdown open';
    if (modal !== document.body) host.style.position = 'absolute';
    host.style.left = (Math.max(8, Math.min(rect.left, window.innerWidth - 188)) - origin.left) + 'px';
    host.style.top = ((rect.bottom + 104 < window.innerHeight ? rect.bottom + 6 : Math.max(8, rect.top - 100)) - origin.top) + 'px';
    modal.appendChild(host);
    this.menu = host;
    const item = (label: string, icon: string, action: () => void, danger = false) => <li role="none">
      <button type="button" role="menuitem" className={'hasIcon' + (danger ? ' is-danger' : '')} onclick={action}>
        <Icon className="Button-icon" name={icon}/><span className="Button-label">{label}</span>
      </button></li>;
    m.mount(host, {view: () => <ul className="Dropdown-menu dropdown-menu" role="menu" aria-label="Аватар персонажа">
      {item('Заменить', 'fas fa-camera', () => {this.closeMenu(true); this.input?.click();})}
      {item('Удалить', uiIcon('actions.delete'), () => {
        this.closeMenu(true);
        this.attrs.state.change(() => {this.attrs.state.view!.draft.payload.avatar = null;});
      }, true)}
    </ul>});
    const on = (target: EventTarget, type: string, handler: EventListener, capture = false) => {
      target.addEventListener(type, handler, capture);
      this.menuListeners.push([target, type, handler, capture]);
    };
    on(document, 'pointerdown', event => {
      if (!host.contains(event.target as Node) && !anchor.contains(event.target as Node)) this.closeMenu();
    }, true);
    on(document, 'keydown', event => {
      const key = (event as KeyboardEvent).key;
      if (key === 'Escape') {event.stopPropagation(); event.preventDefault();}
      if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(key)) {
        event.preventDefault(); event.stopPropagation();
        const buttons = Array.from(host.querySelectorAll<HTMLButtonElement>('button'));
        const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
        buttons[key === 'Home' ? 0 : key === 'End' ? buttons.length - 1
          : (index + (key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length]?.focus();
      }
      if (key === 'Tab') this.closeMenu();
    }, true);
    // Ядро закрывает модальное окно по keyup, поэтому Escape перехватываем до body.
    on(document, 'keyup', event => {
      if ((event as KeyboardEvent).key === 'Escape') {
        event.stopPropagation(); event.preventDefault(); this.closeMenu(true);
      }
    }, true);
    on(window, 'resize', () => this.closeMenu());
    on(window, 'scroll', () => this.closeMenu(), true);
    host.querySelector<HTMLButtonElement>('button')?.focus();
    m.redraw();
  }

  private closeMenu(restoreFocus = false): void {
    for (const [target, type, handler, capture] of this.menuListeners) target.removeEventListener(type, handler, capture);
    this.menuListeners = [];
    if (this.menu) {m.mount(this.menu, null); this.menu.remove();}
    this.menu = null;
    if (restoreFocus) this.button?.focus();
    m.redraw();
  }

  onremove(vnode: Mithril.VnodeDOM<{state: CreationState}, this>): void {
    this.closeMenu();
    super.onremove(vnode);
  }

  async upload(file: File): Promise<void> {
    const state = this.attrs.state;
    if (state.uploading || state.conflict || state.creating || state.createdId !== null) return;
    this.error = '';
    if (file.size > 2 * 1024 * 1024) {this.error = 'Выберите изображение размером не более 2 МБ'; return;}
    state.uploading = true;
    m.redraw();
    try {
      const body = new FormData();
      body.append('image', file);
      const response = await app.request<{data: {avatar: string}}>({
        method: 'POST', url: app.forum.attribute<string>('apiUrl') + '/roleplay/creation/avatar',
        body, serialize: value => value, errorHandler: () => {},
      });
      state.change(() => {state.view!.draft.payload.avatar = response.data.avatar;});
      await state.flush();
    } catch (error) {
      this.error = requestMessage(error, 'Не удалось загрузить аватар. Попробуйте обновить страницу');
    } finally {
      state.uploading = false;
      m.redraw();
    }
  }

  view(): JSX.Element {
    const state = this.attrs.state;
    const url = characterAvatarUrl(state.view!.draft.payload.avatar);
    const disabled = state.uploading || state.creating || state.conflict || state.createdId !== null;
    return <div className="RpWizard-avatar">
      <button type="button" className="RpWizard-avatarButton" disabled={disabled}
        oncreate={vnode => {this.button = vnode.dom as HTMLButtonElement;}}
        aria-label={url ? 'Действия с аватаром персонажа' : 'Загрузить аватар персонажа'}
        aria-haspopup={url ? 'menu' : undefined} aria-expanded={url ? !!this.menu : undefined}
        onclick={(event: MouseEvent) => {
          if (!url) this.input?.click();
          else if (this.menu) this.closeMenu();
          else this.openMenu(event.currentTarget as HTMLElement);
        }}>
        {state.uploading ? <Icon name="fas fa-spinner fa-spin"/>
          : url ? <img src={url} alt="Аватар персонажа"/> : <Icon name={uiIcon('actions.upload')}/>}
      </button>
      <div>
        <strong>Аватар персонажа</strong>
        <p>Он будет отображаться везде, где требуется изображение персонажа</p>
        <input hidden type="file" accept="image/jpeg,image/png,image/webp" aria-label="Файл аватара персонажа"
          disabled={disabled} oncreate={vnode => {this.input = vnode.dom as HTMLInputElement;}}
          onchange={(event: Event) => {
            const input = event.target as HTMLInputElement, file = input.files?.[0];
            input.value = '';
            if (file) void this.upload(file);
          }}/>
        {this.error && <Feedback message={this.error}/>}
      </div>
    </div>;
  }
}
