export default {
  view(vnode: {attrs: Record<string, unknown>; children: import("mithril").Children}) {
    return m('a', vnode.attrs, vnode.children);
  },
};