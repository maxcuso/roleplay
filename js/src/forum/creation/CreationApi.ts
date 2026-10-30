import app from 'flarum/forum/app';
import type {CreationTransport, CreationView, CreationCommand, CreationPayload, CreationStep} from './types';

/** Только транспорт. Ошибки показывает мастер рядом с действием, без второй общей всплывающей ошибки. */
export default class CreationApi implements CreationTransport {
  private url(): string { return app.forum.attribute<string>('apiUrl') + '/roleplay/creation'; }
  async read(): Promise<CreationView> {
    const response = await app.request<{data: CreationView}>({method: 'GET', url: this.url() + '/draft', errorHandler: () => {}});
    return response.data;
  }
  async save(body: {revision: number; catalog_revision: number; rules_hash: string; step: CreationStep; payload: CreationPayload}): Promise<CreationView> {
    const response = await app.request<{data: CreationView}>({method: 'PUT', url: this.url() + '/draft', body, errorHandler: () => {}});
    return response.data;
  }
  async create(body: CreationCommand): Promise<{character_id: number}> {
    const response = await app.request<{data: {character_id: number}}>({method: 'POST', url: this.url(), body, errorHandler: () => {}});
    return response.data;
  }
}
