import app from 'flarum/forum/app';
import {extend} from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import showModal from '../../common/showModal';
import CreationWizardModal from './CreationWizardModal';
import {uiIcon} from '../../common/uiIcons';

/** Меняем только навигацию в двух тегах. Права на создание обычных тем остаются форумными. */
export default function addCreationButton(): void {
  extend(IndexSidebar.prototype, 'items', function(items) {
    const slug = app.search.state.params().tags;
    if (slug !== 'role' && slug !== 'characters') return;

    const discussion = items.has('newDiscussion') ? items.get('newDiscussion') : null;
    items.remove('newDiscussion');
    if (app.session.user && app.forum.attribute('roleplayCanCreate')) {
      // Тот же слот и класс сохраняют расположение основной кнопки, в том числе на телефоне.
      items.add('newDiscussion', m(Button, {
        onclick: () => showModal(CreationWizardModal, {}), icon: uiIcon('wizard.title'),
        className: 'Button Button--primary IndexPage-newDiscussion', itemClassName: 'App-primaryControl',
      }, 'Создать персонажа'), 100);
    }

    // У модераторов обычная тема остаётся второй кнопкой, без второго мобильного primaryControl.
    if (app.forum.attribute('roleplayCanModerate') && discussion && typeof discussion === 'object' && !Array.isArray(discussion)) {
      items.add('roleplayDiscussion', {...discussion, attrs: {...discussion.attrs, itemClassName: undefined}}, 90);
    }
  });
}
