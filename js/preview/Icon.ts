/** Значки рисуются теми же классами Font Awesome, что и на форуме. */
export default {
  view(vnode: {attrs: {name: string; className?: string; title?: string}}) {
    const {name, className = '', ...attrs} = vnode.attrs;
    return m('i', {...attrs, className: 'icon ' + name + ' ' + className, 'aria-hidden': 'true'});
  },
};