import app from 'flarum/forum/app';
import {extend} from 'flarum/common/extend';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import RoleplayProfilePage from './profile/RoleplayProfilePage';
import {uiIcon} from '../common/uiIcons';
import addCreationButton from './creation/addCreationButton';
import guardWizardClose from './creation/guardWizardClose';

app.initializers.add('maxcuso-roleplay', () => {
  app.routes['user.roleplay'] = {path: '/u/:username/roleplay', component: RoleplayProfilePage};
  guardWizardClose();

  extend(UserPage.prototype, 'navItems', function(this: UserPage, items: ItemList<Mithril.Children>) {
    if (!this.user || !app.forum.attribute('roleplayCanView')) return;
    items.add('roleplay', m(LinkButton, {
      href: app.route('user.roleplay', {username: this.user.slug()}), icon: uiIcon('extension'),
    }, 'Ролевая'), 80);
  });
});

// Подключаемся после flarum-tags: его обработчик сначала меняет обычную кнопку темы.
app.initializers.add('maxcuso-roleplay-creation-button', addCreationButton, -10);
