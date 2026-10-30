import app from 'flarum/common/app';

/** В Flarum 2 initializers запускаются раньше создания модели app.forum. */
export function eventsAvailable(): boolean {
  const forum = app.data.resources?.find(resource => resource.type === 'forums');
  return Boolean(forum?.attributes?.roleplayEventsAvailable);
}
