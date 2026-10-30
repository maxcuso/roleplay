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
import GuidePage from './guide/GuidePage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import {registerCharacterNotifications} from './notifications/CharacterNotification';
import './events';
import './episodes';
import './checks';
import WorkspacePage from './workspace/WorkspacePage';

// Форма экспортируется и используется кнопкой завершения в редакторе ответа.
export {default as EpisodeCompletionModal} from './episodes/EpisodeCompletionModal';

app.initializers.add('maxcuso-roleplay', () => {
  registerCharacterNotifications();
  app.routes['roleplay.guide'] = {path: '/role', component: GuidePage};
  app.routes['user.roleplay'] = {path: '/u/:username/roleplay', component: RoleplayProfilePage};
  app.routes['user.roleplayWorkspace'] = {path: '/u/:username/moderation', component: WorkspacePage};
  guardWizardClose();

  extend(IndexSidebar.prototype, 'items', function(items) {
    items.add('roleplayGuide', m(LinkButton, {
      href: app.route('roleplay.guide'), icon: uiIcon('extension'), className: 'Button Button--link',
    }, 'Ролевая'), 70);
  });

  extend(UserPage.prototype, 'navItems', function(this: UserPage, items: ItemList<Mithril.Children>) {
    if (!this.user || !app.forum.attribute('roleplayCanView')) return;
    if (app.forum.attribute('roleplayCanUseWorkspace') && app.session.user?.id() === this.user.id()) {
      items.add('roleplayWorkspace', m(LinkButton, {
        href: app.route('user.roleplayWorkspace', {username: this.user.slug()}), icon: uiIcon('permissions.moderate'),
      }, 'Модераторская'), 75);
    }
    items.add('roleplay', m(LinkButton, {
      href: app.route('user.roleplay', {username: this.user.slug()}), icon: uiIcon('extension'),
    }, 'Ролевая'), 80);
  });
});

// Подключаемся после flarum-tags: его обработчик сначала меняет обычную кнопку темы.
app.initializers.add('maxcuso-roleplay-creation-button', addCreationButton, -10);
