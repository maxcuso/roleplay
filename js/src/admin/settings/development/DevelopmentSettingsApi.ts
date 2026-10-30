import app from 'flarum/admin/app';
import type {DevelopmentView} from './types';

export default class DevelopmentSettingsApi {
  private url(): string {return app.forum.attribute<string>('apiUrl') + '/roleplay/admin/development';}
  async read(): Promise<DevelopmentView> {
    const response = await app.request<{data: DevelopmentView}>({method: 'GET', url: this.url(), errorHandler: () => {}});
    return response.data;
  }
  async save(data: DevelopmentView): Promise<void> {
    await app.request({method: 'PUT', url: this.url(), body: {revision: data.revision, configuration: data.configuration}, errorHandler: () => {}});
  }
}
