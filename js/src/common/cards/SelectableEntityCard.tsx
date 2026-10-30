import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {uiIcon} from '../uiIcons';

interface Attrs {
  name: string; selected: boolean; disabled: boolean; onchange: () => void;
  readonly?: boolean; hint?: string; showSelection?: boolean;
}

/** Общая рамка выбора для мастера и экипировки.
 * readonly сохраняет отметку надетого предмета в чужом профиле, но не даёт
 * управлять им и не приглушает карточку. Текст остаётся копируемым.
 */
export default class SelectableEntityCard extends Component<Attrs> {
  view(vnode: Mithril.Vnode<Attrs>): Mithril.Children {
    const {selected, disabled, name, onchange, readonly = false, hint, showSelection = true} = this.attrs;
    return <div className={'RpChoice' + (selected && showSelection ? ' is-selected' : '')
      + (showSelection ? '' : ' RpChoice--action')
      + (disabled ? ' is-disabled' : '') + (readonly ? ' is-readonly' : '')}
      title={hint} role={readonly ? undefined : 'button'} tabindex={readonly ? undefined : disabled ? -1 : 0}
      aria-label={readonly ? undefined : name} aria-pressed={readonly ? undefined : selected}
      aria-disabled={readonly ? undefined : disabled}
      onclick={(event: MouseEvent) => {
        if (readonly || disabled) return;
        const selection = window.getSelection();
        // Выделение описания мышью не должно менять выбор карточки.
        if (selection && !selection.isCollapsed && selection.anchorNode
          && (event.currentTarget as HTMLElement).contains(selection.anchorNode)) return;
        onchange();
      }}
      onkeydown={(event: KeyboardEvent) => {
        if (!readonly && event.target === event.currentTarget && ['Enter', ' '].includes(event.key)) {
          event.preventDefault();
          if (!disabled) onchange();
        }
      }}>
      {vnode.children}
      {selected && showSelection && <span className="RpChoice-check" aria-hidden="true"><Icon name={uiIcon('wizard.selected')}/></span>}
    </div>;
  }
}