import type Mithril from 'mithril';

/** Минимальный жизненный цикл Flarum Component для автономного визуального стенда. */
export default class Component<Attrs = Record<string, unknown>> {
  attrs!: Attrs;
  children!: Mithril.Children;
  oninit(vnode: Mithril.Vnode<Attrs>): void {
    this.attrs = vnode.attrs;
    this.children = vnode.children;
  }
  onbeforeupdate(vnode: Mithril.Vnode<Attrs>): void {
    this.attrs = vnode.attrs;
    this.children = vnode.children;
  }
}