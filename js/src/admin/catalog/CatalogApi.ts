import app from 'flarum/admin/app';
import type {CatalogDataSource, CatalogResult, Fields} from '../../common/catalog/types';
import {requestMessage as message} from '../../common/requestError';

/** Общий транспорт. Адрес задаёт описание раздела, а не условие внутри страницы. */
export default class CatalogApi implements CatalogDataSource {
  constructor(private readonly endpoint: string) {}

  private url(id?: number): string {
    return app.forum.attribute<string>('apiUrl') + '/roleplay/admin/' + this.endpoint + (id === undefined ? '' : '/' + id);
  }

  async list(params: Record<string, string | number>): Promise<CatalogResult> {
    const response = await app.request<{data: CatalogResult}>({
      method: 'GET', url: this.url(), params, errorHandler: () => {},
    });
    return response.data;
  }

  async save(id: number | null, revision: number, fields: Fields): Promise<void> {
    await app.request({method: id === null ? 'POST' : 'PUT', url: this.url(id ?? undefined),
      body: {revision, fields}, errorHandler: () => {}});
  }

  async toggle(id: number, revision: number, enabled: boolean): Promise<void> {
    await app.request({method: 'PATCH', url: this.url(id), body: {revision, enabled}, errorHandler: () => {}});
  }

  async remove(id: number, revision: number): Promise<void> {
    await app.request({method: 'DELETE', url: this.url(id), body: {revision}, errorHandler: () => {}});
  }
}

/** Ошибка отображается рядом с действием; подробности неожиданного сбоя остаются на сервере. */
export function requestMessage(error: unknown): string {
  return message(error, 'Не удалось выполнить запрос. Попробуйте обновить страницу');
}
