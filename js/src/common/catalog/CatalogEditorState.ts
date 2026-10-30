import type {CatalogDataSource, Fields} from './types';
import {requestMessage} from '../requestError';
import {clearInputFeedback} from '../messageKind';

/** Черновик формы не связан с объектом строки. Ошибка сохраняет введённые значения. */
export default class CatalogEditorState {
  readonly values: Fields;
  saving = false;
  uploading = false;
  error = '';

  constructor(initial: Fields, readonly id: number | null, readonly revision: number,
    private readonly api: CatalogDataSource) {
    this.values = JSON.parse(JSON.stringify(initial)) as Fields;
  }

  text(key: string): string { return String(this.values[key] ?? ''); }
  number(key: string): number { return Number(this.values[key] ?? 0); }
  flag(key: string): boolean { return Boolean(this.values[key]); }
  get imageEndpoint(): string { return this.api.imageEndpoint ?? '/roleplay/admin/perk-image'; }
  set(key: string, value: unknown): void { this.values[key] = value; clearInputFeedback(this); }

  async save(): Promise<boolean> {
    if (this.saving || this.uploading) return false;
    this.saving = true;
    this.error = '';
    m.redraw();
    try {
      await this.api.save(this.id, this.revision, this.values);
      return true;
    } catch (error) {
      this.error = requestMessage(error, 'Не удалось сохранить изменения. Попробуйте обновить страницу');
      return false;
    } finally {
      this.saving = false;
      m.redraw();
    }
  }
}
