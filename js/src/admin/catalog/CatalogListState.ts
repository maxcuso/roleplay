import type {CatalogDataSource, CatalogResult} from '../../common/catalog/types';
import {requestMessage} from '../../common/requestError';

/** Состояние отделено от разметки: поиск и сохранение проверяются без браузера. */
export default class CatalogListState {
  data: CatalogResult = {rows: [], total: 0, page: 1, per_page: 30, revision: 0, lookups: {}};
  search = '';
  filters: Record<string, string> = {};
  page = 1;
  loading = false;
  mutating = false;
  error = '';
  private sequence = 0;
  private disposed = false;
  private searchTimer?: ReturnType<typeof setTimeout>;

  constructor(readonly api: CatalogDataSource, private readonly redraw: () => void = () => m.redraw()) {}

  async load(): Promise<void> {
    if (this.disposed) return;
    const ticket = ++this.sequence;
    this.loading = true;
    this.error = '';
    this.redraw();
    try {
      const data = await this.api.list({search: this.search, page: this.page, ...this.filters});
      // Ответ старого поиска не должен заменить результаты нового запроса.
      if (!this.disposed && ticket === this.sequence) this.data = data;
    } catch (error) {
      if (!this.disposed && ticket === this.sequence) this.error = requestMessage(error, 'Не удалось выполнить запрос. Попробуйте обновить страницу');
    } finally {
      if (!this.disposed && ticket === this.sequence) {
        this.loading = false;
        this.redraw();
      }
    }
  }

  setSearch(value: string): void {
    this.search = value;
    this.page = 1;
    ++this.sequence;
    this.loading = true;
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => void this.load(), 250);
    this.redraw();
  }

  setFilter(key: string, value: string): void {
    this.filters[key] = value;
    this.page = 1;
    clearTimeout(this.searchTimer);
    void this.load();
  }

  async mutate(action: () => Promise<void>): Promise<void> {
    if (this.disposed || this.mutating || this.loading) return;
    this.mutating = true;
    this.error = '';
    this.redraw();
    try {
      await action();
      await this.load();
    } catch (error) {
      this.error = requestMessage(error, 'Не удалось выполнить запрос. Попробуйте обновить страницу');
    } finally {
      this.mutating = false;
      if (!this.disposed) this.redraw();
    }
  }

  dispose(): void {
    this.disposed = true;
    ++this.sequence;
    clearTimeout(this.searchTimer);
  }
}
