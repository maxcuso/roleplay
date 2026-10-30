import app from 'flarum/forum/app';
import {eventsAvailable} from '../../common/eventsAvailable';
import {registerModeratorTools} from './ModeratorTools';

app.initializers.add('maxcuso-roleplay-episode-completion', () => {
  if (!eventsAvailable()) return;
  registerModeratorTools();
}, -100);
