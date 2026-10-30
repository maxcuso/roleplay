import SettingsState from '../settings/SettingsState';

export interface ApplicationSettings {tag_id: number | null; title_template: string; body_template: string}
export interface ApplicationSettingsView {revision: number; application: ApplicationSettings; tags: {id: number; name: string}[]}
export interface ApplicationSettingsTransport {
  read(): Promise<ApplicationSettingsView>;
  save(revision: number, application: ApplicationSettings): Promise<void>;
}
/** Узкая форма сохраняет только настройки литературной темы. */
export default class ApplicationSettingsState extends SettingsState<ApplicationSettingsView> {
  constructor(api: ApplicationSettingsTransport, redraw: () => void) {
    super(() => api.read(), data => api.save(data.revision, data.application), redraw);
  }
}