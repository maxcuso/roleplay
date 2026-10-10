import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import type CreationState from './CreationState';
import {steps} from './types';
import {uiIcon} from '../../common/uiIcons';
import BasicsStep from './steps/BasicsStep';
import AttributesStep from './steps/AttributesStep';
import TrainingStep from './steps/TrainingStep';
import PerksStep from './steps/PerksStep';
import SpellsStep from './steps/SpellsStep';
import LoadoutStep from './steps/LoadoutStep';
import ReviewStep from './steps/ReviewStep';

const screens = {
  basics: BasicsStep, attributes: AttributesStep, training: TrainingStep,
  perks: PerksStep, spells: SpellsStep, loadout: LoadoutStep, review: ReviewStep,
};
interface Attrs {
  state: CreationState;
  embedded?: boolean;
  title?: string; submitLabel?: string; draftOnly?: boolean; notice?: string;
  loggedIn: boolean;
  create: () => Promise<void>;
  profileUrl: (id: number) => string;
}

/** Одна разметка для форума и визуального стенда. Здесь нет HTTP, маршрутизации и тестовых данных. */
export default class CreationWizardForm extends Component<Attrs> {
  view(): Mithril.Children {
    const {state, create, profileUrl, loggedIn, title = 'Создание персонажа', submitLabel = 'Создать персонажа', draftOnly, notice} = this.attrs;
    const view = state.view;
    const index = steps.findIndex(step => step.key === state.step);
    return <main className={this.attrs.embedded ? 'RpWizard' : 'container RpWizard'}>
      {!this.attrs.embedded && <h1><Icon name={uiIcon('wizard.title')}/> {title}</h1>}
      {notice && <p className="RpWizard-help">{notice}</p>}
      {!loggedIn ? <p>Войдите в аккаунт, чтобы создать персонажа.</p> : <>
        {state.error && <div className="RpWizard-error" role="alert">{state.error}
          {state.conflict
            ? <Button className="Button" onclick={() => void state.load()}>Загрузить сохранённый черновик</Button>
            : <Button className="Button" disabled={state.busy} onclick={() => void (
                state.ready ? state.step === 'review' ? create() : state.flush() : state.load()
              )}>Повторить</Button>}
        </div>}
        {!state.ready ? <p role="status">Загрузка мастера…</p> : state.createdId !== null
          ? <div className="RpWizard-scroll"><p role="status">Персонаж создан.</p>
            <LinkButton className="Button Button--primary" href={profileUrl(state.createdId)}>Открыть персонажа в профиле</LinkButton>
            {view?.preview.review && ReviewStep(state)}</div>
          : <>
            <nav key="progress" className="RpWizard-progress" aria-label="Шаги создания">{steps.map((step, position) =>
              <button key={step.key} type="button" className={position > index ? 'is-upcoming' : position === index ? 'is-current is-complete' : 'is-complete'}
                title={step.name} aria-label={step.name} aria-current={position === index ? 'step' : undefined}
                disabled={state.busy || state.creating || position > index && position > 0
                  && !view!.preview.step_validity[steps[position - 1].key]}
                onclick={() => void state.goTo(step.key)}>
                <span className="RpWizard-progressLine" aria-hidden="true"/>
                <Icon name={uiIcon(step.icon)}/>
              </button>)}</nav>
            <div className="RpWizard-scroll" key={state.step}>
              {!view!.catalog.configuration.enabled && <p className="RpWizard-help">Создание персонажей сейчас недоступно.</p>}
              <fieldset className="RpWizard-content" disabled={state.conflict || state.creating}>
                {screens[state.step](state)}
              </fieldset>
              {state.attempted && (view!.preview.errors[state.step] ?? []).length > 0 &&
                <ul className="RpWizard-errors" role="alert">{view!.preview.errors[state.step].map((error, errorIndex) =>
                  <li key={errorIndex}>{error}</li>)}</ul>}
            </div>
            <footer key="navigation" className="RpWizard-navigation">
              <span className="RpWizard-saving" role="status">
                {state.busy ? draftOnly ? 'Проверяем выбор…' : 'Сохраняем…'
                  : draftOnly ? 'Изменения пока не сохранены' : state.dirty ? 'Есть несохранённые изменения' : 'Сохранено'}
              </span>
              {index > 0 && <Button className="Button" disabled={state.busy || state.creating}
                onclick={() => void state.goTo(steps[index - 1].key)}>Назад</Button>}
              {index < steps.length - 1
                ? <Button className="Button Button--primary" disabled={state.busy || state.creating || state.conflict}
                    onclick={() => void state.goTo(steps[index + 1].key)}>Далее</Button>
                : <Button className="Button Button--primary" loading={state.creating}
                    disabled={state.busy || state.conflict || !view!.catalog.configuration.enabled}
                    onclick={() => void create()}>{submitLabel}</Button>}
            </footer>
          </>}
      </>}
    </main>;
  }
}
