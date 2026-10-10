import app from 'flarum/admin/app';
import {requestMessage} from './CatalogApi';

export interface TransferDocument {format: string; version: number; catalogs: Record<string, unknown[]>}
export interface ImportReport {
  revision: number; applied: boolean; new: number; updated: number; skipped: number;
  errors: string[]; warnings: string[];
  rows: {catalog: string; key: string; name: string; action: 'create' | 'update' | 'skip'}[];
}
export type ImportMode = 'skip' | 'update';
export type ExportScope = 'section' | 'filtered' | 'all';

/** Один черновик импорта. Смена файла или режима всегда отменяет прежний предпросмотр. */
export default class CatalogTransferState {
  filename = '';
  document?: TransferDocument;
  mode: ImportMode = 'skip';
  report?: ImportReport;
  busy = false;
  error = '';

  private url(): string { return app.forum.attribute<string>('apiUrl') + '/roleplay/admin/catalog-json'; }

  setMode(mode: ImportMode): void {
    if (this.busy) return;
    this.mode = mode;
    this.report = undefined;
    this.error = '';
  }

  async readFile(file: File): Promise<void> {
    if (this.busy) return;
    this.busy = true;
    this.filename = file.name;
    this.document = undefined;
    this.report = undefined;
    this.error = '';
    m.redraw();
    try {
      if (file.size > 10 * 1024 * 1024) throw new Error('Размер JSON-файла — не более 10 МБ.');
      // BOM встречается в файлах из текстовых редакторов Windows.
      const document = JSON.parse((await file.text()).replace(/^\uFEFF/, '')) as TransferDocument;
      if (!document || document.format !== 'roleplay-catalogs' || document.version !== 1
          || !document.catalogs || Array.isArray(document.catalogs) || typeof document.catalogs !== 'object') {
        throw new Error('Нужен файл roleplay-catalogs версии 1.');
      }
      this.document = document;
    } catch (error) {
      this.error = error instanceof SyntaxError ? 'Не удалось прочитать JSON. Проверь запятые и кавычки.'
        : error instanceof Error ? error.message : 'Не удалось прочитать файл.';
    } finally {this.busy = false; m.redraw();}
  }

  async check(revision: number): Promise<void> {
    if (this.busy || !this.document) return;
    this.report = undefined;
    await this.send(revision, false);
  }

  async apply(): Promise<boolean> {
    if (this.busy || !this.report || this.report.errors.length || this.report.applied) return false;
    await this.send(this.report.revision, true);
    return Boolean(this.report?.applied);
  }

  private async send(revision: number, apply: boolean): Promise<void> {
    this.busy = true;
    this.error = '';
    m.redraw();
    try {
      const response = await app.request<{data: ImportReport}>({method: 'POST', url: this.url(),
        body: {document: this.document, mode: this.mode, revision, apply}, errorHandler: () => {}});
      this.report = response.data;
    } catch (error) {
      // После конфликта версии или сетевой ошибки требуется новый предпросмотр.
      this.report = undefined;
      this.error = requestMessage(error);
    } finally {this.busy = false; m.redraw();}
  }

  async export(catalog: string, scope: ExportScope, filters: Record<string, string | number>): Promise<TransferDocument | undefined> {
    if (this.busy) return;
    this.busy = true;
    this.error = '';
    m.redraw();
    try {
      const response = await app.request<{data: TransferDocument}>({method: 'GET', url: this.url(),
        params: {catalog, scope, ...(scope === 'filtered' ? filters : {})}, errorHandler: () => {}});
      return response.data;
    } catch (error) {this.error = requestMessage(error);}
    finally {this.busy = false; m.redraw();}
  }
}
