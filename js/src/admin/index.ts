import app from 'flarum/admin/app';
import RoleplayPage from './components/RoleplayPage';
import {uiIcon} from '../common/uiIcons';
import {eventsAvailable} from '../common/eventsAvailable';

// Регистрируем страницу расширения; доступ к API отдельно проверяется сервером.
app.initializers.add('maxcuso-roleplay', () => {
  const registry = app.registry.for('maxcuso-roleplay');
  registry.registerPage(RoleplayPage);
  registry.registerPermission({
    permission: 'roleplay.view',
    icon: uiIcon('permissions.view'),
    label: app.translator.trans('maxcuso-roleplay.admin.permissions.view'),
    allowGuest: true,
  }, 'view');
  registry.registerPermission({
    permission: 'roleplay.create',
    icon: uiIcon('permissions.create'),
    label: app.translator.trans('maxcuso-roleplay.admin.permissions.create'),
  }, 'start');
  registry.registerPermission({
    permission: 'roleplay.moderate',
    icon: uiIcon('permissions.moderate'),
    label: app.translator.trans('maxcuso-roleplay.admin.permissions.moderate'),
  }, 'moderate');

  for (const [permission, label] of [
    ['roleplay.workspace', 'Открывать модераторскую'],
    ['roleplay.catalog.view', 'Просматривать рабочие справочники'],
    ['roleplay.catalog.create', 'Создавать перки, заклинания и предметы'],
    ['roleplay.catalog.edit', 'Редактировать перки, заклинания и предметы'],
    ['roleplay.catalog.delete', 'Удалять перки, заклинания и предметы'],
  ]) {
    registry.registerPermission({permission, label, icon: uiIcon('permissions.moderate')}, 'moderate');
  }

  if (eventsAvailable()) {
    // Ключи оставлены прежними: настройки и группы не сбрасываются при переносе.
    registry.registerSetting({
      setting: 'maxcuso-quest-events.quests_enabled', type: 'boolean',
      label: 'Ролевые квесты',
      help: 'Разрешить создание квестов с персонажами, рангами и наградами. Уже созданные события сохраняются',
    });
    registry.registerPermission({
      permission: 'quest-events.create', icon: 'fas fa-calendar-plus', label: 'Создавать ролевые квесты и события',
    }, 'start');
    registry.registerPermission({
      permission: 'quest-events.createForum', icon: 'far fa-calendar-plus', label: 'Создавать форумные события',
    }, 'start');
    registry.registerPermission({
      permission: 'quest-events.manage', icon: 'fas fa-calendar-alt', label: 'Управлять событиями',
    }, 'moderate');
  }
});
