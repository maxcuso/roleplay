import app from 'flarum/forum/app';
import {extend} from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import textContrastClass from 'flarum/common/helpers/textContrastClass';
import type Model from 'flarum/common/Model';
import CreationWizardModal from './CreationWizardModal';
import CharacterButton from './CharacterButton';
import {uiIcon} from '../../common/uiIcons';

/** Верхний блок ролевого тега. Действие создания темы и его права остаются штатными. */
export default function addCreationButton(): void {
  extend(IndexSidebar.prototype, 'items', function(items) {
    const slug = app.search.state.params().tags;
    if (slug !== 'role' && slug !== 'characters') return;

    const discussion = items.has('newDiscussion') ? items.get('newDiscussion') : null;
    const node = discussion && typeof discussion === 'object' && !Array.isArray(discussion) ? discussion : null;

    if (node) {
      if (app.session.user && app.forum.attribute('roleplayCanModerate')) {
        items.add('roleplayDiscussion', {...node, attrs: {
          ...node.attrs, itemClassName: undefined,
          className: [node.attrs.className, 'Button--block'].filter(Boolean).join(' '),
          'aria-label': 'Создать тему',
        }, children: ['Создать тему']}, 110);
      }
      // flarum-tags и другие расширения читают newDiscussion после нас: сохраняем узел,
      // но скрываем его и убираем перенос в шапку телефона через App-primaryControl.
      node.attrs = {...node.attrs, itemClassName: 'RpSidebarItem--hidden'};
    }

    const tag = app.store.all<Model>('tags').find(row => row.attribute<string>('slug') === slug);
    const color = tag?.attribute<string>('color');
    items.remove('roleplayGuide'); // Ссылка на путеводитель теперь находится в этом блоке.
    items.add('roleplayActions', m('div.RpTagButtons', [
      app.session.user && app.forum.attribute('roleplayCanCreate') ? m(Button, {
        onclick: () => void CreationWizardModal.open(), icon: uiIcon('wizard.title'),
        className: 'Button Button--primary Button--block' + (color ? ' Button--tagColored ' + textContrastClass(color) : ''),
        style: color ? {'--color': color} : undefined,
      }, 'Создать персонажа') : null,
      m('div.RpTagButtons-links', [
        m(LinkButton, {
          href: app.route('roleplay.guide'), icon: uiIcon('extension'), className: 'Button',
        }, 'Путеводитель'),
        m(CharacterButton),
      ]),
    ]), 100);
  });
}
