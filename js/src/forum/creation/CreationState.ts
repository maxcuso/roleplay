import {requestStatus as status, requestMessage as message} from '../../common/requestError';
import {steps} from './types';
import {canAdjustAttribute} from './helpers';
import type {CreationTransport, CreationView, CreationCommand, CreationStep, TrainingSlot} from './types';

/** Перенесена очередь старого WizardState. Здесь нет порогов прокачки и зависимости от Flarum. */
export default class CreationState {
  view: CreationView | null = null;
  step: CreationStep = 'basics';
  ready = false;
  dirty = false;
  busy = false;
  creating = false;
  conflict = false;
  attempted = false;
  error = '';
  createdId: number | null = null;
  private generation = 0;
  private timer?: ReturnType<typeof setTimeout>;
  private pending: Promise<boolean> | null = null;
  private command: CreationCommand | null = null;
  private disposed = false;

  constructor(private readonly api: CreationTransport, private readonly redraw: () => void,
    private readonly operationKey: () => string = () => crypto.randomUUID()) {}

  async load(): Promise<void> {
    this.error = '';
    this.busy = true;
    try {
      const view = await this.api.read();
      if (this.disposed) return;
      this.normalizeMaps(view);
      this.view = view;
      this.step = steps.some(step => step.key === view.draft.step) ? view.draft.step as CreationStep : 'basics';
      this.ready = true;
      this.dirty = false;
      this.conflict = false;
      this.createdId = view.existing_character_id;
      if (view.draft.catalog_revision !== view.catalog.revision || view.draft.rules_hash !== view.catalog.rules_hash) {
        this.error = 'Правила обновились. Проверь сохранённый выбор перед продолжением.';
        this.dirty = true;
      }
      // Пустой черновик получает минимальные значения из настроенного распределения.
      if (!Object.keys(view.draft.payload.base_attributes).length) {
        const minimum = Math.min(...view.catalog.configuration.attribute_distribution);
        for (const row of view.catalog.definitions.attributes) {
          view.draft.payload.base_attributes[String(row.slug)] = minimum;
        }
        this.dirty = true;
      }
      const current = steps.findIndex(step => step.key === this.step);
      for (let index = 0; index < current; index++) {
        if (!view.preview.step_validity[steps[index].key]) { this.step = steps[index].key; break; }
      }
    } catch (error) {
      this.error = message(error, 'Не удалось открыть мастер. Проверь вход в аккаунт и права доступа.');
    } finally {
      this.busy = false;
      this.notify();
    }
  }

  change(action: () => void): void {
    if (!this.view || this.creating || this.createdId !== null || this.conflict) return;
    action();
    this.generation++;
    this.dirty = true;
    this.attempted = false;
    this.error = '';
    clearTimeout(this.timer);
    this.timer = setTimeout(() => { void this.flush(); }, 600);
    this.notify();
  }

  /** Читаем актуальный черновик при каждом клике, включая ввод между двумя redraw/сохранениями. */
  adjustAttribute(slug: string, delta: -1 | 1): void {
    if (!this.view) return;
    const distribution = this.view.catalog.configuration.attribute_distribution;
    const rows = this.view.catalog.definitions.attributes;
    const minimum = Math.min(...distribution);
    const values = rows.map(row => Number(this.view!.draft.payload.base_attributes[String(row.slug)] ?? minimum));
    const index = rows.findIndex(row => row.slug === slug);
    if (!canAdjustAttribute(distribution, values, index, delta)) return;
    this.change(() => {this.view!.draft.payload.base_attributes[slug] = values[index] + delta;});
  }

  /** Замена раннего выбора очищает его последующие повышения, но сохраняет другую характеристику. */
  setTraining(slot: TrainingSlot, id: number | null): void {
    this.change(() => {
      const field = slot.kind === 'skill' ? 'skills' : 'proficiencies';
      const rows = this.view!.catalog.definitions[field];
      const choices = this.view!.draft.payload[field];
      const index = slot.payload_index ?? choices.length;
      this.view!.draft.payload[field] = choices.filter((value, position) =>
        position < index || rows.find(row => row.id === value)?.attribute_id !== slot.attribute_id);
      if (id !== null) this.view!.draft.payload[field].splice(Math.min(index, this.view!.draft.payload[field].length), 0, id);
    });
  }

  async flush(): Promise<boolean> {
    clearTimeout(this.timer);
    if (!this.ready || !this.view || this.conflict || this.disposed || this.createdId !== null) return false;
    if (this.pending) {
      const saved = await this.pending;
      return saved && this.dirty ? this.flush() : saved;
    }
    if (!this.dirty) return true;
    this.pending = this.save();
    const result = await this.pending;
    this.pending = null;
    return result && this.dirty ? this.flush() : result;
  }

  private async save(): Promise<boolean> {
    this.busy = true;
    this.error = '';
    this.notify();
    const generation = this.generation;
    const view = this.view!;
    // Данные запроса замораживаем: ввод во время запроса останется для следующего сохранения.
    const payload = JSON.parse(JSON.stringify(view.draft.payload));
    try {
      const response = await this.api.save({revision: view.draft.revision, catalog_revision: view.catalog.revision,
        rules_hash: view.catalog.rules_hash, step: this.step, payload});
      if (this.disposed) return false;
      this.normalizeMaps(response);
      view.draft.id = response.draft.id;
      view.draft.revision = response.draft.revision;
      view.draft.catalog_revision = response.draft.catalog_revision;
      view.draft.rules_hash = response.draft.rules_hash;
      if (generation === this.generation) {
        this.view = response;
        this.dirty = false;
      }
      return true;
    } catch (error) {
      this.conflict = status(error) === 409;
      this.error = message(error, 'Не удалось сохранить. Изменения остаются в форме — попробуй ещё раз.');
      return false;
    } finally {
      this.busy = false;
      this.notify();
    }
  }

  async goTo(target: CreationStep): Promise<void> {
    this.attempted = true;
    if (!await this.flush()) { this.notify(); return; }
    const index = steps.findIndex(step => step.key === target);
    if (index > steps.findIndex(step => step.key === this.step) && index > 0
      && !this.view!.preview.step_validity[steps[index - 1].key]) { this.notify(); return; }
    this.change(() => { this.step = target; });
    await this.flush();
  }

  async create(): Promise<void> {
    if (this.creating || this.createdId !== null) return;
    this.attempted = true;
    if (!await this.flush() || !this.view!.preview.valid) { this.notify(); return; }
    this.creating = true;
    this.error = '';
    const view = this.view!;
    if (!this.command || this.command.draft_revision !== view.draft.revision) {
      this.command = {operation_key: this.operationKey(), draft_id: view.draft.id, draft_revision: view.draft.revision,
        catalog_revision: view.catalog.revision, rules_hash: view.catalog.rules_hash};
    }
    try {
      this.createdId = (await this.api.create(this.command)).character_id;
    } catch (error) {
      this.conflict = status(error) === 409;
      this.error = message(error, 'Ответ не получен. Повтори действие: запрос защищён от повторной выдачи.');
    } finally {
      this.creating = false;
      this.notify();
    }
  }

  /**
   * PHP может прислать пустую карту как []. Свойства с именами у массива не попадают
   * в JSON.stringify: иначе прибавленные характеристики и выбор набора исчезают при сохранении.
   * Объект создаём и при загрузке, и после ответа, включая ранее сохранённые черновики.
   */
  private normalizeMaps(view: CreationView): void {
    const payload = view.draft.payload;
    payload.base_attributes = {...payload.base_attributes};
    payload.loadout = {...payload.loadout};
  }

  dispose(): void { clearTimeout(this.timer); this.disposed = true; }
  private notify(): void { if (!this.disposed) this.redraw(); }
}
