import app from 'flarum/forum/app';
import RegistrationChoiceModal from '../components/RegistrationChoiceModal';

const states = new Map();

export function acquireEvent(id) {
  const key = `${app.session.user?.id() || 'guest'}:${id}`;
  if (!states.has(key)) states.set(key, { id, key, refs: 0, data: null, loading: false, busy: false, error: null, promise: null });
  const state = states.get(key);
  state.refs++;
  return state;
}

export function releaseEvent(state) {
  if (--state.refs === 0) states.delete(state.key);
}

function message(error) {
  if (typeof error.response?.error === 'string') return error.response.error;
  if (error.status === 401 || error.status === 403) return 'Недостаточно прав. Возможно, нужно войти на форум заново.';
  if (error.status === 404) return 'Событие не найдено.';
  return 'Не удалось получить ответ сервера. Обнови данные перед повторной попыткой.';
}

function request(options) {
  return app.request({ timeout: 15000, errorHandler: () => {}, ...options });
}

export function refreshEvent(state) {
  if (state.promise) return state.promise;
  state.loading = true;
  state.promise = (async () => {
    try {
      const response = await request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/quest-events/' + state.id });
      state.data = response.data;
      state.error = null;
    } catch (error) {
      state.error = message(error);
      // Do not leave an actionable card based on an old response.
      state.data = null;
    } finally {
      state.loading = false;
      state.promise = null;
      m.redraw();
    }
  })();
  m.redraw();
  return state.promise;
}

export async function changeParticipation(state, leave = false, input = null) {
  if (state.busy || state.loading || !state.data) return;
  // Выбор не занимает место. Сервер повторно проверит персонажа и лимиты при отправке.
  const options = !leave && !input ? state.data.registrationOptions : null;
  if (options) {
    if (options.error || !options.choices?.length) {
      app.alerts.show({type: 'warning', dismissible: true}, options.error || 'Нет доступных вариантов участия.');
      return false;
    }
    if (options.choices.length > 1) {
      app.modal.show(RegistrationChoiceModal, {options, eventTitle: state.data.title,
        onselect: values => changeParticipation(state, false, values)});
      return false;
    }
    input = {[options.field]: options.choices[0].id};
  }
  state.busy = true;
  state.error = null;
  m.redraw();
  let failure = null;
  try {
    await request({
      method: leave ? 'DELETE' : 'POST',
      url: app.forum.attribute('apiUrl') + '/quest-events/' + state.id + '/participants' + (leave ? '/me' : ''),
      body: leave ? undefined : (input || {}),
    });
  } catch (error) {
    failure = message(error);
  }
  // Refresh on rejection too: another participant may have filled the last slot.
  await refreshEvent(state);
  if (failure) app.alerts.show({ type: 'warning', dismissible: true }, failure);
  state.busy = false;
  m.redraw();
  return !failure;
}

