import {requestMessage, requestStatus} from '../../common/requestError';

/** Общий цикл настроек: загрузка, сохранение, защита от поздних ответов и устаревшей версии. */
export default class SettingsState<T extends {revision: number}> {
  data: T | null = null;
  loading = false; saving = false; stale = false; error = ''; saved = false;
  private disposed = false; private generation = 0;
  constructor(private readonly readData: () => Promise<T>, private readonly writeData: (data: T) => Promise<void>,
    private readonly redraw: () => void) {}
  async load(): Promise<void> {
    if (this.saving) return;
    const generation = ++this.generation;
    this.loading = true; this.error = ''; this.saved = false; this.notify();
    try {
      const data = await this.readData();
      if (!this.disposed && generation === this.generation) {this.data = data; this.stale = false;}
    } catch (error) {
      if (!this.disposed && generation === this.generation) this.error = requestMessage(error, 'Не удалось загрузить настройки.');
    } finally {
      if (generation === this.generation) {this.loading = false; this.notify();}
    }
  }
  async save(): Promise<void> {
    if (!this.data || this.loading || this.saving || this.stale) return;
    this.saving = true; this.saved = false; this.error = ''; this.notify();
    // Вход формы может изменяться во время запроса. Отправляем неизменяемую копию.
    const submitted = JSON.parse(JSON.stringify(this.data)) as T;
    try {
      await this.writeData(submitted);
      const data = await this.readData();
      if (!this.disposed) {this.data = data; this.saved = true;}
    } catch (error) {
      if (!this.disposed) {
        this.error = requestMessage(error, 'Не удалось сохранить настройки. Обнови форму и проверь результат.');
        const status = requestStatus(error);
        this.stale = status === 409 || status === undefined || status === 0 || status >= 500;
      }
    } finally {this.saving = false; this.notify();}
  }
  dispose(): void {this.disposed = true; this.generation++;}
  private notify(): void {if (!this.disposed) this.redraw();}
}