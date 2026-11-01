import app from 'flarum/forum/app';

/** Общий гостевой аккаунт Арены остаётся посетителем, хотя у него есть сессия Flarum. */
export function creationRequiresLogin(): boolean {
  return !app.session.user || app.forum.attribute('roleplayCreationRequiresLogin') === true
    || app.session.user.attribute('arenaGuest') === true;
}

export function creationEmailUnconfirmed(): boolean {
  return !creationRequiresLogin() && app.session.user?.isEmailConfirmed() !== true;
}

/** Кнопка остаётся видимой для входа и для пояснения ограничения по почте. */
export function creationButtonAvailable(): boolean {
  return creationRequiresLogin() || creationEmailUnconfirmed() || Boolean(app.forum.attribute('roleplayCanCreate'));
}

/** Одна проверка для лендинга, профиля, боковой панели и прямого открытия мастера. */
export function allowCreationOpening(): boolean {
  if (creationRequiresLogin()) {
    // После входа другой аккаунт не должен попасть в скрытый гостевой бой Арены.
    if (app.session.user && /^\/d\//.test(m.route.get?.() ?? '')) m.route.set('/');
    void app.modal.show(() => import('flarum/forum/components/LogInModal'), {});
    return false;
  }
  if (creationEmailUnconfirmed()) {
    app.alerts.show({type: 'warning'}, 'Сначала подтвердите почту');
    return false;
  }
  if (!app.forum.attribute('roleplayCanCreate')) {
    app.alerts.show({type: 'warning'}, 'У вас нет разрешения на создание персонажа');
    return false;
  }
  return true;
}
