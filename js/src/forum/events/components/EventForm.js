import Component from 'flarum/common/Component';
import app from 'flarum/common/app';
import buildEventBbcode from '../utils/buildEventBbcode';
import { questColors, defaultColor } from '../utils/eventAppearance';

// Общие поля создания и редактирования. Слой окна задаёт штатный Modal Flarum.
export default class EventForm extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.reset();
    this.selection = null;
    this.drafts = [];
    this.draftsLoading = false;
    this.draftsError = null;
    this.draftsAvailable = true;
    this.removed = false;
  }

  reset() {
    clearTimeout(this.leaderTimer);
    this.leaderRequest = (this.leaderRequest || 0) + 1;
    this.leaderQuery = '';
    this.leaderResults = [];
    this.leaderLoading = false;
    this.leaderError = null;
    // Rewards are display-only properties; zero means no reward of this kind.
    this.values = { title: '', description: '', minimum: '2', maximum: '4', delay: '12', leader: '', start: '', draft: '', eventType: this.questsEnabled() ? 'quest' : 'forum', difficulty: '3', customColor: defaultColor, rewardExperience: '0', rewardGold: '0', rewardItems: '0' };
    this.error = null;
  }

  questsEnabled() {
    const value = app.data?.settings?.['maxcuso-quest-events.quests_enabled'] ?? app.forum.attribute('questEventsQuestsEnabled') ?? true;
    return (this.isManagement || !!app.forum.attribute('canCreateQuestEvents'))
      && !!app.forum.attribute('questEventsRoleplayAvailable') && (value === true || value === 1 || value === '1');
  }

  oncreate() { this.loadDrafts(); }
  view() { return this.getMenu(); }

  onremove(vnode) {
    this.removed = true;
    clearTimeout(this.leaderTimer);
    this.leaderRequest++;
    super.onremove?.(vnode);
  }

  async loadDrafts() {
    if (this.draftsLoading) return;
    this.draftsLoading = true;
    this.draftsError = null;
    m.redraw();
    try {
      const response = await app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/quest-event-drafts', timeout: 15000 });
      if (this.removed) return;
      this.drafts = response.data;
      this.draftsAvailable = response.meta.available;
    } catch (error) {
      if (!this.removed) this.draftsError = 'Не удалось загрузить черновики.';
    } finally {
      this.draftsLoading = false;
      if (!this.removed) m.redraw();
    }
  }

  field(key, label, attributes = {}) {
    return m('label.QuestEventForm-field', [
      this.label(label, { title: 'heading', description: 'align-left', minimum: 'user', maximum: 'user-friends', delay: 'hourglass-half', start: 'calendar-alt', customColor: 'palette', rewardExperience: 'star', rewardGold: 'coins', rewardItems: 'box-open' }[key]),
      m(key === 'description' ? 'textarea.FormControl' : 'input.FormControl', {
        name: key,
        ...attributes,
        value: this.values[key],
        oninput: (event) => { this.values[key] = event.target.value; this.error = null; },
      }),
    ]);
  }

  searchLeader(value) {
    clearTimeout(this.leaderTimer);
    const request = ++this.leaderRequest;
    this.leaderQuery = value;
    this.values.leader = '';
    this.leaderResults = [];
    this.leaderError = null;
    this.error = null;
    const query = value.trim().replace(/^@/, '');
    this.leaderLoading = !!query;
    if (!query) return;
    this.leaderTimer = setTimeout(() => this.loadLeaders(query, request), 300);
  }

  async loadLeaders(query, request) {
    try {
      // Use the same API and visibility rules as Flarum's user search.
      const users = await app.store.find('users', { filter: { q: query }, page: { limit: 5 } });
      if (this.removed || request !== this.leaderRequest) return;
      this.leaderResults = users.map(user => ({ id: String(user.id()), username: user.username(), name: user.displayName(), avatar: user.avatarUrl() }));
      if (!users.length) this.leaderError = 'Пользователи не найдены. Попробуй другой ник.';
    } catch (error) {
      if (this.removed || request !== this.leaderRequest) return;
      this.leaderError = 'Не удалось найти пользователей. Измени ник, чтобы повторить поиск.';
    } finally {
      if (!this.removed && request === this.leaderRequest) {
        this.leaderLoading = false;
        m.redraw();
      }
    }
  }

  selectLeader(user) {
    clearTimeout(this.leaderTimer);
    this.leaderRequest++;
    this.values.leader = user.id;
    this.leaderQuery = user.username;
    this.leaderResults = [];
    this.leaderLoading = false;
    this.leaderError = null;
    this.error = null;
  }

  leaderField() {
    return m('.QuestEventForm-leader', [
      m('label.QuestEventForm-field', [
        this.label('Ведущий', 'crown'),
        m('input.FormControl', {
          name: 'leaderUsername', type: 'text', autocomplete: 'off', maxlength: 100,
          placeholder: 'Начни вводить ник', value: this.leaderQuery,
          oninput: event => this.searchLeader(event.target.value),
        }),
      ]),
      this.leaderLoading ? m('p.helpText', { role: 'status' }, 'Ищем пользователей…') : null,
      this.leaderError ? m('p.helpText', { role: 'status' }, this.leaderError) : null,
      this.leaderResults.length ? m('ul.QuestEventForm-userResults', { 'aria-label': 'Найденные пользователи' }, this.leaderResults.map(user =>
        m('li', { key: user.id }, m('button', { type: 'button', onclick: () => this.selectLeader(user) }, [
          user.avatar ? m('img', { src: user.avatar, alt: '' }) : m('span.QuestEventForm-userInitial', { 'aria-hidden': 'true' }, user.name.slice(0, 1).toUpperCase()),
          m('span', [m('strong', user.name), m('small', '@' + user.username)]),
        ]))
      )) : null,
      m('p.helpText', this.values.leader ? `Выбран ведущий: @${this.leaderQuery}` : this.isManagement ? 'Пустое поле — ведущий не назначен.' : 'По умолчанию — автор сообщения.'),
    ]);
  }

  label(text, icon) {
    return m('span.QuestEventForm-label', [icon ? m(`i.fas.fa-${icon}`, { 'aria-hidden': 'true' }) : null, text]);
  }

  section(title, icon, children) {
    return m('fieldset.QuestEventForm-section', [m('legend', this.label(title, icon)), ...children]);
  }

  getMenu() {
    const number = { type: 'number', min: 1, max: 4294967295, step: 1, required: true, disabled: !!this.lockTiming };
    const rewardNumber = { type: 'number', min: 0, max: 4294967295, step: 1, required: true };
    return m('div.QuestEventEditForm', {
      role: 'region',
      'aria-label': this.isManagement ? 'Редактирование события' : 'Создание события',
      onclick: event => event.stopPropagation(),
      onkeydown: event => {
        if (event.key === 'Escape') { event.stopPropagation(); event.preventDefault(); this.close(); }
      },
    }, m('form.QuestEventForm-layout', { onsubmit: event => this.insert(event) }, [
      m('.QuestEventForm-body', [
        this.section('Основное', 'feather-alt', [
          this.field('title', 'Название', { required: true, minlength: 3, maxlength: 80, placeholder: 'Как называется событие?' }),
          this.field('description', 'Описание', { rows: 2, maxlength: 1000, placeholder: 'Коротко: что ждёт участников' }),
          m('.QuestEventForm-row', [
            m('label.QuestEventForm-field', [this.label('Тип', 'shapes'), m('select.FormControl', {
              name: 'eventType', value: this.values.eventType,
              onchange: event => { this.values.eventType = event.target.value; this.error = null; },
            }, [this.questsEnabled() || this.attrs?.event?.eventType === 'quest' ? m('option', { value: 'quest' }, 'Квест') : null, m('option', { value: 'forum' }, 'Форумное событие')])]),
            this.values.eventType === 'quest' ? m('label.QuestEventForm-field', [
              this.label('Ранг', 'signal'),
              m('select.FormControl', { name: 'difficulty', value: this.values.difficulty, onchange: event => { this.values.difficulty = event.target.value; } },
                questColors.map((color, index) => m('option', { key: color, value: String(index + 1) }, ['Обычный', 'Авантюрный', 'Героический', 'Эпический', 'Легендарный', 'Драконий'][index]))),
              m('.QuestEventForm-colorPreview', { style: { backgroundColor: questColors[Number(this.values.difficulty) - 1] }, 'aria-hidden': 'true' }),
            ]) : this.field('customColor', 'Цвет', { type: 'color', required: true }),
          ]),
        ]),
        this.section('Участники', 'users', [
          m('.QuestEventForm-row', [this.field('minimum', 'Минимум', number), this.field('maximum', 'Максимум', number)]),
          this.leaderField(),
        ]),
        this.section('Старт', 'clock', [
          m('.QuestEventForm-row.QuestEventForm-row--schedule', [
            this.field('delay', 'Интервал, ч', number),
            this.field('start', 'Дата и время', { type: 'datetime-local', step: 1, disabled: !!this.lockTiming }),
          ]),
          m('p.helpText', 'Дата необязательна. Часовой пояс — как в браузере.'),
          m('details.QuestEventForm-details', [m('summary', 'Как работает запуск'),
            m('p.helpText', this.lockTiming ? 'Событие уже стартовало или завершилось. Условия набора и черновик менять нельзя.' : 'После набора минимума старт назначается на выбранную будущую дату. Если её нет или она прошла, используется интервал. При наборе максимума событие стартует сразу, кроме случая с будущей датой.'),
            this.isManagement && !this.lockTiming ? m('p.helpText', 'Изменение условий набора или даты пересчитывает таймер от момента сохранения. Правки названия и описания таймер не меняют.') : null,
          ]),
        ]),
        this.values.eventType === 'quest' ? this.section('Награда', 'gift', [
          m('.QuestEventForm-rewardFields', [this.field('rewardExperience', 'Опыт · XP', rewardNumber), this.field('rewardGold', 'Золото', rewardNumber), this.field('rewardItems', 'Предметы', rewardNumber)]),
          m('p.helpText', 'Нулевые значения не показываются в карточке.'),
        ]) : null,
        this.section('Будущая тема', 'file-alt', [
          m('label.QuestEventForm-field', [this.label('Черновик', 'pen'), m('select.FormControl', {
            name: 'draft', value: this.values.draft,
            disabled: !!this.lockTiming || this.draftsLoading || (!this.isManagement && (!this.draftsAvailable || !!this.draftsError)),
            onchange: event => { this.values.draft = event.target.value; },
          }, [
            ...(this.isManagement ? [m('option', { key: 'keep', value: 'keep' }, 'Сохранить текущий черновик')] : []),
            m('option', { key: 'none', value: '' }, this.draftsLoading ? 'Загрузка…' : 'Без черновика — из описания'),
            ...this.drafts.map(draft => m('option', { key: draft.id, value: String(draft.id), disabled: !!draft.unavailableReason }, draft.title + (draft.unavailableReason ? ' — ' + draft.unavailableReason : ''))),
          ])]),
          this.draftsError ? m('p.QuestEventForm-error', { role: 'alert' }, [this.draftsError, ' ', m('button.Button.Button--link', { type: 'button', onclick: () => this.loadDrafts() }, 'Повторить')]) : null,
          m('p.helpText', this.draftsAvailable ? 'Последние 10 черновиков. При старте берётся актуальная версия; оригинал сохраняется.' : 'Черновики недоступны для вашей учётной записи.'),
        ]),
      ]),
      m('.QuestEventForm-footer', [
        this.error ? m('p.QuestEventForm-error', { role: 'alert' }, this.error) : null,
        m('.QuestEventForm-actions', [
          m('span.helpText', this.isManagement ? 'Изменения в карточке события' : 'Создастся после отправки сообщения'),
          m('button.Button.Button--link', { type: 'button', disabled: !!this.saving, onclick: () => this.close() }, 'Отмена'),
          m('button.Button.Button--primary', { type: 'submit', disabled: !!this.saving }, this.saving ? 'Сохраняем…' : this.isManagement ? 'Сохранить' : 'Вставить'),
        ]),
      ]),
    ]));
  }

  insert(event) {
    event.preventDefault();
    event.stopPropagation();
    try {
      if (this.values.draft && (this.draftsLoading || this.draftsError || !this.drafts.some((draft) => String(draft.id) === this.values.draft && !draft.unavailableReason))) {
        throw new Error('Выбранный черновик недоступен. Обнови список и выбери его заново.');
      }
      if (this.leaderQuery.trim() && !this.values.leader) {
        throw new Error('Выбери ведущего из подсказок или очисти поле, чтобы назначить автора сообщения.');
      }
      const code = buildEventBbcode(this.values);
      this.attrs.oninsert(code);
      this.reset();
      this.close();
    } catch (error) {
      this.error = error.message;
    }
  }

  close() {
    this.attrs.oncancel();
  }
}

