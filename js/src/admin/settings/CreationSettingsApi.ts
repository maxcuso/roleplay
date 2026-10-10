import app from 'flarum/admin/app';
import type {Lookups} from '../../common/catalogData';
import type {ApplicationSettings} from '../application/ApplicationSettingsState';

export interface LoadoutOption {key: string; name: string; items: {item_id: number; quantity: number}[]}
export interface LoadoutGroup {key: string; name: string; automatic: boolean; min: number; max: number; options: LoadoutOption[]}
export interface CreationSettings {
  enabled: boolean; max_characters: number; attribute_distribution: number[];
  starting_spell_count: number; starting_gold: number; starting_experience: number;
  perk_sources: {race?: number; skill?: number};
  loadout: LoadoutGroup[]; application: ApplicationSettings;
}
export interface CreationSettingsView {revision: number; configuration: CreationSettings; lookups: Lookups}
export default class CreationSettingsApi {
  private url(): string {return app.forum.attribute<string>('apiUrl') + '/roleplay/admin/creation';}
  async read(): Promise<CreationSettingsView> {
    return (await app.request<{data: CreationSettingsView}>({method: 'GET', url: this.url(), errorHandler: () => {}})).data;
  }
  async save(data: CreationSettingsView): Promise<void> {
    await app.request({method: 'PUT', url: this.url(), body: {revision: data.revision, configuration: data.configuration}, errorHandler: () => {}});
  }
}