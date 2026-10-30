import app from 'flarum/admin/app';
import type {ApplicationSettings, ApplicationSettingsView, ApplicationSettingsTransport} from './ApplicationSettingsState';

export default class ApplicationSettingsApi implements ApplicationSettingsTransport {
  private url(): string {return app.forum.attribute<string>('apiUrl') + '/roleplay/admin/application';}
  async read(): Promise<ApplicationSettingsView> {
    const result = await app.request<{data: ApplicationSettingsView}>({method: 'GET', url: this.url(), errorHandler: () => {}});
    return result.data;
  }
  async save(revision: number, application: ApplicationSettings): Promise<void> {
    await app.request({method: 'PUT', url: this.url(), body: {revision, application}, errorHandler: () => {}});
  }
}