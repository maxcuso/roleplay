import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Feedback from '../../common/components/Feedback';
import {requestMessage} from '../../common/requestError';
import showModal from '../../common/showModal';
import EventEditModal from '../events/components/EventEditModal';

interface Episode {
  id: number;
  title: string;
  discussionId: number | null;
  status: 'recruiting' | 'active' | 'completed';
  participantCount: number;
  participants: Array<{id: number; displayName: string; slug?: string; username?: string; character?: {id: number | null; name: string}}>;
  canManage: boolean;
  startError?: string;
}
interface Result {rows: Episode[]; nextBefore: number | null}

/** Использует записи Events и существующий редактор, отдельной базы эпизодов нет. */
export default class WorkspaceEpisodes extends Component {
  type = 'quest';
  status = 'current';
  rows: Episode[] = [];
  nextBefore: number | null = null;
  loading = false;
  error = '';
  private sequence = 0;
  private disposed = false;

  oninit(): void { void this.load(); }
  onremove(): void { this.disposed = true; ++this.sequence; }

  async load(more = false): Promise<void> {
    const ticket = ++this.sequence;
    this.loading = true;
    this.error = '';
    m.redraw();
    try {
      const params: Record<string, string | number> = {type: this.type, status: this.status};
      if (more && this.nextBefore !== null) params.before = this.nextBefore;
      const result = await app.request<{data: Result}>({method: 'GET',
        url: app.forum.attribute<string>('apiUrl') + '/roleplay/workspace/episodes', params, errorHandler: () => {}});
      if (this.disposed || ticket !== this.sequence) return;
      this.rows = more ? [...this.rows, ...result.data.rows] : result.data.rows;
      this.nextBefore = result.data.nextBefore;
    } catch (error) {
      if (!this.disposed && ticket === this.sequence) this.error = requestMessage(error, 'Не удалось загрузить эпизоды. Попробуйте обновить список');
    } finally {
      if (!this.disposed && ticket === this.sequence) {this.loading = false; m.redraw();}
    }
  }

  private filter(key: 'type' | 'status', value: string): void {
    this[key] = value;
    this.rows = [];
    this.nextBefore = null;
    void this.load();
  }

  view(): JSX.Element {
    const labels = {recruiting: 'Набор', active: 'Идёт', completed: 'Завершён'};
    return <section aria-label="Мои эпизоды">
      <div className="RpWorkspace-toolbar">
        <select className="FormControl" aria-label="Тип событий" value={this.type}
          onchange={(event: Event) => this.filter('type', (event.target as HTMLSelectElement).value)}>
          <option value="quest">Ролевые истории</option><option value="forum">Форумные события</option>
        </select>
        <Button className={'Button ' + (this.status === 'current' ? 'Button--primary' : '')}
          onclick={() => this.filter('status', 'current')}>Текущие</Button>
        <Button className={'Button ' + (this.status === 'completed' ? 'Button--primary' : '')}
          onclick={() => this.filter('status', 'completed')}>Архив</Button>
        <Button className="Button" icon="fas fa-sync-alt" disabled={this.loading} onclick={() => void this.load()}>Обновить</Button>
      </div>
      {this.error && <Feedback message={this.error}/>}
      {this.loading && <p className="helpText" role="status">Загрузка…</p>}
      {!this.loading && !this.error && !this.rows.length && <p className="helpText">Здесь появятся истории, в которых вы назначены ведущим</p>}
      <div className="RpWorkspace-episodes" aria-busy={String(this.loading)}>
        {this.rows.map(episode => <article className="RpWorkspace-episode" key={episode.id}>
          <div className="RpWorkspace-episodeHeading">
            <h3>{episode.discussionId ? <a href={app.forum.attribute<string>('baseUrl') + '/d/' + episode.discussionId}>{episode.title}</a> : episode.title}</h3>
            <span className="helpText">{labels[episode.status]}</span>
          </div>
          <p className="helpText">Участников: {episode.participantCount}</p>
          {episode.participants.length > 0 && <ul className="RpWorkspace-participants">
            {episode.participants.map(participant => <li key={participant.id}><a
              href={participant.character?.id
                ? app.route('user.roleplay', {username: participant.slug ?? participant.username ?? String(participant.id)}) + '?character=' + participant.character.id
                : app.route('user', {username: participant.slug ?? participant.username ?? String(participant.id)})}
              title={participant.character?.id ? 'Открыть анкету персонажа' : 'Открыть профиль'}>
              {participant.character?.name || participant.displayName}
            </a></li>)}
          </ul>}
          {episode.startError && <Feedback message={episode.startError} inline/>}
          {episode.canManage && <div className="RpWorkspace-actions"><Button className="Button" icon="fas fa-pen"
            onclick={() => showModal(EventEditModal, {event: episode, onsaved: () => void this.load()})}>Управление</Button></div>}
        </article>)}
      </div>
      {this.nextBefore !== null && <Button className="Button" disabled={this.loading} onclick={() => void this.load(true)}>Показать ещё</Button>}
    </section>;
  }
}
