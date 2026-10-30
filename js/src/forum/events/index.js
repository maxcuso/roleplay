import app from 'flarum/forum/app';
import EventsPage from './components/EventsPage';
import Page from 'flarum/common/components/Page';

class EventsForumPage extends Page {
  view() { return m(EventsPage); }
}
import { extend } from 'flarum/common/extend';
import mountEventCards from './mountEventCards';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import {eventsAvailable} from '../../common/eventsAvailable';
import EventCreateButton from './components/EventCreateButton';

app.initializers.add('maxcuso-roleplay-events', () => {
  // Пока старый QuestEvents включён, интерфейс обслуживает только он.
  if (!eventsAvailable()) return;
  mountEventCards();
  // Создание событий доступно отдельно от инструментов модератора.
  extend('flarum/common/components/TextEditor', 'toolbarItems', function (items) {
    const composer = this.attrs.composer;
    if (!composer || !(app.forum.attribute('canCreateQuestEvents') || app.forum.attribute('canCreateForumEvents'))) return;
    items.add('quest-event', m(EventCreateButton, {composer, disabled: Boolean(this.disabled)}), 5);
  });
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canManageQuestEvents')) return;
    items.add('quest-events-management', m(LinkButton, { href: app.route('questEvents'), icon: 'fas fa-calendar-alt' }, 'Управление событиями'), 5);
  });
  app.routes.questEvents = {
    path: '/quest-events',
    component: EventsForumPage,
  };
});

export default {};

