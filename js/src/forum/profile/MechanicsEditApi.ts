import app from 'flarum/forum/app';
import type {CreationTransport, CreationView, CreationCommand, CreationPayload, CreationStep} from '../creation/types';

type EditView = CreationView & {character_revision: number};

/** Транспорт редактора повторно использует CreationState.
 * save только проверяет форму; PUT при create атомарно меняет существующую анкету.
 * revision формы и revision персонажа независимы: просмотр шагов не меняет персонажа.
 */
export default class MechanicsEditApi implements CreationTransport {
  private view: EditView | null = null;
  constructor(private readonly id: number) {}
  private url(): string {return app.forum.attribute<string>('apiUrl') + '/roleplay/characters/' + this.id + '/mechanics';}

  async read(): Promise<EditView> {
    const result = await app.request<{data: EditView}>({method: 'GET', url: this.url(), errorHandler: () => {}});
    this.view = result.data;
    return this.view;
  }

  async save(body: {revision: number; catalog_revision: number; rules_hash: string; step: CreationStep; payload: CreationPayload}): Promise<EditView> {
    const result = await app.request<{data: EditView}>({method: 'POST', url: this.url(),
      body: {...body, character_revision: this.view!.character_revision}, errorHandler: () => {}});
    this.view = result.data;
    return this.view;
  }

  async create(command: CreationCommand): Promise<{character_id: number}> {
    const view = this.view!;
    const result = await app.request<{data: {character_id: number}}>({method: 'PUT', url: this.url(),
      body: {operation_key: command.operation_key, revision: view.character_revision,
        catalog_revision: command.catalog_revision, rules_hash: command.rules_hash,
        payload: view.draft.payload}, errorHandler: () => {}});
    return result.data;
  }
}