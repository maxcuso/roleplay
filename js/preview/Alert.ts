import type Mithril from 'mithril';

/** Оболочка предупреждения для автономного стенда. На форуме используется ядро Flarum. */
export default {
  view(vnode: Mithril.Vnode<{type?: string; controls?: Mithril.Children}>): Mithril.Children {
    return m('div', {className: 'Alert Alert--' + (vnode.attrs.type ?? 'warning'), role: 'alert'},
      vnode.children, vnode.attrs.controls);
  },
};
