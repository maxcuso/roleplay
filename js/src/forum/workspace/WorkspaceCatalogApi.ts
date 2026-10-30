import app from 'flarum/forum/app';
import type {CatalogDataSource, CatalogResult, Fields} from '../../common/catalog/types';

export type WorkspaceCatalogKey = 'perks' | 'spells' | 'items';

/** Существующая форма редактора, отдельный проверяемый на сервере транспорт. */
export default class WorkspaceCatalogApi implements CatalogDataSource {
  readonly imageEndpoint = '/roleplay/management/perk-image';
  constructor(private readonly key: WorkspaceCatalogKey) {}
  private url(id?: number): string {
    return app.forum.attribute<string>('apiUrl') + '/roleplay/workspace/catalogs/' + this.key
      + (id === undefined ? '' : '/' + id);
  }
  async list(params: Record<string, string | number>): Promise<CatalogResult> {
    return (await app.request<{data: CatalogResult}>({method: 'GET', url: this.url(), params, errorHandler: () => {}})).data;
  }
  async save(id: number | null, revision: number, fields: Fields): Promise<void> {
    await app.request({method: id === null ? 'POST' : 'PUT', url: this.url(id ?? undefined), body: {revision, fields}, errorHandler: () => {}});
  }
  async toggle(id: number, revision: number, enabled: boolean): Promise<void> {
    await app.request({method: 'PATCH', url: this.url(id), body: {revision, enabled}, errorHandler: () => {}});
  }
  async remove(id: number, revision: number): Promise<void> {
    await app.request({method: 'DELETE', url: this.url(id), body: {revision}, errorHandler: () => {}});
  }
}
