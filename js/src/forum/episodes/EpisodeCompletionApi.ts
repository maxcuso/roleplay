import app from 'flarum/forum/app';
import type {CatalogDataSource, CatalogResult, Fields} from '../../common/catalog/types';
import type {CompletionCharacter, CompletionContext, CompletionDataSource, CompletionPayload, RewardKind} from './completionTypes';

class CompletionSelectionApi implements CatalogDataSource {
  constructor(private readonly base: string, private readonly kind: string) {}
  async list(params: Record<string, string | number>): Promise<CatalogResult> {
    return (await app.request<{data: CatalogResult}>({method: 'GET', url: this.base + '/catalogs/' + this.kind,
      params, errorHandler: () => {}})).data;
  }
  async save(_id: number | null, _revision: number, _fields: Fields): Promise<void> {throw new Error('В этом окне доступен только выбор награды');}
  async toggle(): Promise<void> {throw new Error('В этом окне доступен только выбор награды');}
  async remove(): Promise<void> {throw new Error('В этом окне доступен только выбор награды');}
}

/** Подготовка BB-кода не выполняет команды персонажа или закрытия темы. */
export default class EpisodeCompletionApi implements CompletionDataSource {
  readonly characters: CatalogDataSource;
  private readonly base: string;
  private prepared: {json: string; key: string} | null = null;
  constructor(discussionId: number) {
    this.base = app.forum.attribute<string>('apiUrl') + '/roleplay/episodes/' + discussionId + '/completion';
    this.characters = new CompletionSelectionApi(this.base, 'characters');
  }
  async context(): Promise<CompletionContext> {
    return (await app.request<{data: CompletionContext}>({method: 'GET', url: this.base, errorHandler: () => {}})).data;
  }
  catalog(kind: RewardKind): CatalogDataSource {return new CompletionSelectionApi(this.base, kind);}
  async character(id: number): Promise<CompletionCharacter> {
    return (await app.request<{data: CompletionCharacter}>({method: 'GET', url: this.base + '/characters/' + id, errorHandler: () => {}})).data;
  }
  async prepare(payload: CompletionPayload): Promise<string> {
    const json = JSON.stringify(payload);
    // Потеря ответа не создаёт новое намерение с теми же настройками.
    if (!this.prepared || this.prepared.json !== json) this.prepared = {json, key: crypto.randomUUID()};
    return (await app.request<{data: {code: string}}>({method: 'POST', url: this.base,
      body: {operation_key: this.prepared.key, payload}, errorHandler: () => {}})).data.code;
  }
}
