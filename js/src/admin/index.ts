import app from 'flarum/admin/app';
import RoleplayPage from './components/RoleplayPage';
import {uiIcon} from '../common/uiIcons';

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
});