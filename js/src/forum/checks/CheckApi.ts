import app from 'flarum/forum/app';
import type {CheckContext, CheckCharacter, CharacterChoice, RequestPayload, RollPayload} from './types';

export default class CheckApi {
  private key = ''; private fingerprint = '';
  constructor(readonly discussionId: number | null, readonly requestId: string | null = null) {}
  private query(): Record<string, string | number> {return {
    ...(this.discussionId ? {discussion_id: this.discussionId} : {}), ...(this.requestId ? {request_id: this.requestId} : {}),
  };}
  private base(): string {return app.forum.attribute<string>('apiUrl') + '/roleplay/checks';}
  async context(): Promise<CheckContext> {return (await app.request<{data: CheckContext}>({method: 'GET', errorHandler: () => {}, url: this.base() + '/context', params: this.query()})).data;}
  async character(id: number): Promise<CheckCharacter> {return (await app.request<{data: CheckCharacter}>({method: 'GET', errorHandler: () => {}, url: this.base() + '/characters/' + id, params: this.query()})).data;}
  async targets(search: string, page: number): Promise<{rows: CharacterChoice[]; page: number; total: number}> {
    return (await app.request<{data: {rows: CharacterChoice[]; page: number; total: number}}>({method: 'GET', errorHandler: () => {}, url: this.base() + '/targets', params: {...this.query(), search, page}})).data;
  }
  async prepare(type: 'request' | 'roll', payload: RequestPayload | RollPayload): Promise<string> {
    const fingerprint = JSON.stringify({type, payload});
    if (fingerprint !== this.fingerprint) {this.fingerprint = fingerprint; this.key = crypto.randomUUID();}
    return (await app.request<{data: {code: string}}>({method: 'POST', errorHandler: () => {}, url: this.base() + '/plans', body: {
      operation_key: this.key, discussion_id: this.discussionId, type, payload,
    }})).data.code;
  }
}
