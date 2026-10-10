import m from 'mithril';
import CreationState from '../src/forum/creation/CreationState';
import CreationWizardForm from '../src/forum/creation/CreationWizardForm';
import {steps} from '../src/forum/creation/types';
import type {CreationStep} from '../src/forum/creation/types';
import DemoTransport, {type Scenario} from './DemoTransport';
import Icon from 'flarum/common/components/Icon';
import {uiIcon} from '../src/common/uiIcons';

const isStage = location.pathname === '/stage.html';
const validStep = (key: string): key is CreationStep => steps.some(step => step.key === key);
const scenarios = {normal: 'Обычный', empty: 'Пустой', long: 'Длинный текст', limit: 'Лимит выбора'};
const storageKey = 'roleplay-visual-preview-v1';

if (!isStage) {
  const iframe = document.getElementById('wizard-frame') as HTMLIFrameElement;
  let step: CreationStep = 'basics', scenario: Scenario = 'normal', width = '980';
  const send = (action: string, value?: string) => iframe.contentWindow?.postMessage({action, value}, location.origin);
  const applyWidth = () => {
    iframe.style.width = width === 'fit' ? '100%' : width + 'px';
    iframe.style.minHeight = '790px';
    iframe.style.height = '790px';
  };
  m.mount(document.getElementById('preview-toolbar')!, {
    view: () => <div className="RpPreview-toolbar">
      <strong>Roleplay · мастер создания</strong>
      <div className="RpPreview-controls">
        <label>Ширина <select value={width} onchange={(e: Event) => {
          width = (e.target as HTMLSelectElement).value; applyWidth();
        }}>{['320', '480', '620', '708', '980', 'fit'].map(value =>
          <option value={value}>{value === 'fit' ? 'Всё окно' : value + ' px'}</option>)}</select></label>
        <label>Пример <select value={scenario} onchange={(e: Event) => {
          scenario = (e.target as HTMLSelectElement).value as Scenario; send('scenario', scenario);
        }}>{Object.entries(scenarios).map(([key, name]) => <option value={key}>{name}</option>)}</select></label>
        <button onclick={() => send('reset')}>Сбросить пример</button>
      </div>
      <div className="RpPreview-controls">{steps.map((item, index) => <button
        className={step === item.key ? 'is-selected' : ''} onclick={() => {step = item.key; send('step', item.key);}}>
        {index + 1}. {item.name}
      </button>)}</div>
      <small>Тестовые данные. Персонажи не создаются. Итоговые числа демонстрационные.
        Изменения стилей появляются автоматически.</small>
    </div>,
  });
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== iframe.contentWindow) return;
    if (event.data.action === 'step' && validStep(event.data.value)) {step = event.data.value; m.redraw();}
  });
  applyWidth();
} else {
  let scenario: Scenario = 'normal', transport = new DemoTransport(scenario);
  let open = true;
  let state = new CreationState(transport, () => m.redraw());
  const tellParent = () => {
    // Высота viewport постоянна: содержимое окна прокручивается внутри мастера.
    // Подгонка iframe по body создала бы цикл при высоте окна, заданной через vh.
    parent.postMessage({action: 'step', value: state.step}, location.origin);
  };
  async function reset(nextScenario: Scenario = scenario): Promise<void> {
    const step = state.step;
    state.dispose();
    scenario = nextScenario;
    transport = new DemoTransport(scenario);
    state = new CreationState(transport, () => m.redraw());
    await state.load();
    state.step = step;
    // Пустой пример получает минимальные характеристики при load; завершаем их
    // демонстрационное сохранение, чтобы статус формы не зависал на «не сохранено».
    await state.flush();
    m.redraw();
  }
  // Пересборка TS перезагружает только стенд. Черновик примера переживает эту перезагрузку.
  try {
    const stored = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null');
    if (stored?.payload && validStep(stored.step)) {
      scenario = stored.scenario in scenarios ? stored.scenario : 'normal';
      transport = new DemoTransport(scenario);
      state = new CreationState(transport, () => m.redraw());
      await transport.save({revision: 1, step: stored.step, payload: stored.payload});
    }
  } catch { sessionStorage.removeItem(storageKey); }
  m.mount(document.getElementById('preview-stage')!, {
    // Только оболочка окна Flarum. Форма и её геометрия берутся из общего кода.
    view: () => open ? <div className="Modal in RpWizardModal">
      <div className="Modal-content">
        <div className="Modal-close"><button type="button" className="Button" aria-label="Закрыть"
          onclick={async () => {if (await state.flush()) {open = false; m.redraw();}}}><Icon name={uiIcon('actions.close')}/></button></div>
        <div className="Modal-header"><h3><Icon name={uiIcon('wizard.title')}/> Создание персонажа</h3></div>
        <div className="Modal-body"><CreationWizardForm state={state} loggedIn={true} embedded={true}
          create={async () => {await state.create(); m.redraw();}}
          profileUrl={() => '#example-created'}/></div>
      </div>
    </div> : <button type="button" className="Button Button--primary" onclick={() => {open = true; m.redraw();}}>Создать персонажа</button>,
    oncreate: tellParent,
    onupdate: tellParent,
  });
  await state.load();
  m.redraw();
  window.addEventListener('message', async event => {
    if (event.origin !== location.origin || event.source !== parent) return;
    if (event.data.action === 'step' && validStep(event.data.value)) {
      // Панель стенда позволяет открыть любой экран без прохождения всех предыдущих.
      await state.flush(); state.step = event.data.value; open = true; m.redraw();
    }
    if (event.data.action === 'scenario' && event.data.value in scenarios) await reset(event.data.value);
    if (event.data.action === 'reset') await reset();
  });
  window.addEventListener('pagehide', () => {
    if (state.view) sessionStorage.setItem(storageKey, JSON.stringify({
      payload: state.view.draft.payload, step: state.step, scenario,
    }));
  });
}

// CSS обновляем без потери ввода. После сборки TS меняется только iframe/стенд.
const updates = new EventSource('/events');
updates.addEventListener('styles', () => {
  document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]').forEach(link => {
    link.href = new URL(link.href).pathname + '?v=' + Date.now();
  });
});
updates.addEventListener('bundle', () => {if (isStage) location.reload();});
