export default {
  view(vnode: {attrs: Record<string, unknown>; children: import("mithril").Children}) {
    const {loading, ...attrs} = vnode.attrs;
    return m('button', {type: 'button', ...attrs}, vnode.children);
  },
};