import Dropdown, {type IDropdownAttrs} from 'flarum/common/components/Dropdown';
import Button from 'flarum/common/components/Button';
import type ComposerState from 'flarum/forum/states/ComposerState';
import type Mithril from 'mithril';
import {uiIcon} from '../../common/uiIcons';
import CompletionButton from './CompletionButton';
import type {ModeratorContext} from './ModeratorTools';

interface Attrs extends IDropdownAttrs {composer: ComposerState; context: ModeratorContext; disabled?: boolean;
  actions?: Mithril.Children[]; toolLabel?: string; toolIcon?: string}

/** Обычный Dropdown Flarum управляет открытием, клавиатурой и закрытием.
 * Верхний слой браузера не даёт панели с overflow обрезать само меню. */
export default class ModeratorMenu extends Dropdown<Attrs> {
  view(vnode: Mithril.Vnode<Attrs, this>): JSX.Element {
    this.attrs.onshow = this.reveal;
    this.attrs.onhide = this.collapse;
    return super.view({...vnode, children: this.attrs.actions ?? this.actionItems()});
  }
  /** Действия дополняются модулями Roleplay, само меню остаётся штатным Dropdown. */
  actionItems(): Mithril.Children[] {
    const {composer, context, disabled} = this.attrs;
    return [
      context.canComplete && context.discussion
        ? m(CompletionButton, {composer, discussion: context.discussion, disabled})
        : m('span', {title: context.reason}, m(Button, {type: 'button', className: 'Button Button--icon Button--link',
          icon: uiIcon('extension'), disabled: true, 'aria-label': 'Завершение эпизода', title: context.reason})),
    ];
  }
  getButton(): Mithril.Vnode {
    return m(Button, {type: 'button', className: 'Dropdown-toggle Button Button--icon Button--link',
      icon: this.attrs.toolIcon ?? uiIcon('editor.moderation'), title: this.attrs.toolLabel ?? 'Модератор', 'aria-label': this.attrs.toolLabel ?? 'Модератор',
      'aria-haspopup': 'menu', 'aria-expanded': String(this.showing), 'data-toggle': 'dropdown',
      disabled: this.attrs.disabled, onkeydown: this.keydown});
  }
  getMenu(items: Mithril.Vnode[]): Mithril.Vnode {
    return m('ul.Dropdown-menu.dropdown-menu.RpModeratorMenu', {popover: 'manual', role: 'menu', 'aria-label': this.attrs.toolLabel ?? 'Модератор', onkeydown: this.keydown}, items);
  }
  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    super.oncreate(vnode);
    window.addEventListener('resize', this.position);
    document.addEventListener('scroll', this.position, true);
  }
  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    this.collapse();
    this.closeWatcher?.destroy();
    window.removeEventListener('resize', this.position);
    document.removeEventListener('scroll', this.position, true);
    super.onremove(vnode);
  }
  private menu(): HTMLElement | null {return this.element.querySelector('.RpModeratorMenu');}
  private keydown = (event: KeyboardEvent): void => {
    if (event.key !== 'Escape' || !this.showing) return;
    // Не передаём Escape глобальному обработчику закрытия самого редактора.
    event.preventDefault(); event.stopPropagation();
    this.element.querySelector<HTMLButtonElement>('.Dropdown-toggle')?.click();
  };
  private reveal = (): void => {
    const menu = this.menu();
    if (menu?.showPopover && !menu.matches(':popover-open')) menu.showPopover();
    this.position();
  };
  private collapse = (): void => {
    const menu = this.menu();
    if (menu?.hidePopover && menu.matches(':popover-open')) menu.hidePopover();
  };
  private position = (): void => {
    if (!this.showing) return;
    const menu = this.menu(), button = this.element.querySelector('.Dropdown-toggle');
    if (!menu || !button) return;
    const anchor = button.getBoundingClientRect(), box = menu.getBoundingClientRect();
    const width = document.documentElement.clientWidth || window.innerWidth;
    const left = Math.max(8, Math.min(Math.round(anchor.left), width - box.width - 8));
    const above = Math.round(anchor.top - box.height - 6);
    const top = above >= 8 ? above : Math.max(8, Math.min(anchor.bottom + 8, window.innerHeight - box.height - 8));
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  };
}
