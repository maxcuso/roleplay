import app from 'flarum/forum/app';
import type {CatalogDataSource, CatalogResult, Fields} from '../../common/catalog/types';

/** Транспорт только создания. Административные изменение/удаление сюда намеренно не подключены. */
export default class ManagementCatalogApi implements CatalogDataSource {
  readonly imageEndpoint = '/roleplay/management/perk-image';
  createdId: number | null = null;
  constructor(private readonly key: 'perks' | 'spells' | 'items') {}
  private url(): string { return app.forum.attribute<string>('apiUrl') + '/roleplay/management/catalogs/' + this.key; }
  async list(): Promise<CatalogResult> {
    return (await app.request<{data: CatalogResult}>({method: 'GET', url: this.url(), errorHandler: () => {}})).data;
  }
  async save(id: number | null, revision: number, fields: Fields): Promise<void> {
    if (id !== null) throw new Error('Здесь можно только создать новую сущность.');
    const result = await app.request<{data: {id: number}}>({method: 'POST', url: this.url(), body: {revision, fields}, errorHandler: () => {}});
    this.createdId = result.data.id;
  }
  async toggle(): Promise<void> { throw new Error('Переключение справочников доступно в админке.'); }
  async remove(): Promise<void> { throw new Error('Удаление справочников доступно в админке.'); }
}
