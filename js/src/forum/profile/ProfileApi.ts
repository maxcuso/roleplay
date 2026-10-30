import app from 'flarum/forum/app';
import type {ProfileTransport, ProfileView, CharacterList, EquipmentCommand, ReviewCommand, IdentityCommand, ProgressionCommand, ManagementCommand} from './types';

/** Только транспорт. Анкета и окна не считают параметры и не решают, кому разрешены действия. */
export default class ProfileApi implements ProfileTransport {
  private url(): string { return app.forum.attribute<string>('apiUrl') + '/roleplay'; }
  async list(userId: number, before?: number): Promise<CharacterList> {
    const result = await app.request<{data: CharacterList}>({
      method: 'GET', url: this.url() + '/users/' + userId + '/characters',
      params: before === undefined ? {} : {before}, errorHandler: () => {},
    });
    return result.data;
  }
  async read(characterId: number): Promise<ProfileView> {
    const result = await app.request<{data: ProfileView}>({
      method: 'GET', url: this.url() + '/characters/' + characterId, errorHandler: () => {},
    });
    return result.data;
  }
  equip(id: number, body: EquipmentCommand): Promise<ProfileView> { return this.change(id, 'equipment', 'POST', body); }
  review(id: number, body: ReviewCommand): Promise<ProfileView> { return this.change(id, 'review', 'POST', body); }
  correct(id: number, body: IdentityCommand): Promise<ProfileView> { return this.change(id, 'identity', 'PUT', body); }
  progress(id: number, body: ProgressionCommand): Promise<ProfileView> {return this.change(id, 'progression', 'POST', body);}
  manage(id: number, body: ManagementCommand): Promise<ProfileView> {return this.change(id, 'management', 'POST', body);}
  private async change(id: number, path: string, method: 'POST' | 'PUT',
    body: EquipmentCommand | ReviewCommand | IdentityCommand | ProgressionCommand | ManagementCommand): Promise<ProfileView> {
    const result = await app.request<{data: ProfileView}>({
      method, url: this.url() + '/characters/' + id + '/' + path, body, errorHandler: () => {},
    });
    return result.data;
  }
}