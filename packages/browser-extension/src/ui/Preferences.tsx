import {Button, Input, Switch} from '@heroui/react';
import {
  Bug01Icon, CodeIcon, CommandIcon, CropIcon, FolderDownloadIcon, Settings02Icon,
} from '@hugeicons/core-free-icons';
import {useRef, useState, type ReactNode} from 'react';
import {shortcutActions, shortcutFromEvent} from '../core/shortcuts';
import type {Preferences as CapturePreferences, Snapshot} from '../core/types';
import {httpUrl, permissionPattern} from '../core/urls';
import {ext, send} from '../core/webextension';
import {Choice, Icon, words} from './shared';

function Section({icon, title, children}: {icon: Parameters<typeof Icon>[0]['icon']; title: string; children: ReactNode}) {
  return <section className="fc-preference-section">
    <h2><Icon icon={icon} size={16}/>{title}</h2>
    {children}
  </section>;
}

function SwitchRow({label, description, value, onChange}: {
  label: string; description?: string; value: boolean; onChange: (value: boolean) => void;
}) {
  return <div className="fc-preference-row">
    <Switch aria-label={label} size="sm" isSelected={value} onChange={onChange}>
      <Switch.Content>{label}</Switch.Content>
      <Switch.Control><Switch.Thumb/></Switch.Control>
    </Switch>
    {description && <p>{description}</p>}
  </div>;
}

export function Preferences({snapshot, onError, onConnect}: {
  snapshot: Snapshot; onError: (message: string) => void; onConnect: () => void;
}) {
  const [preferences, setPreferences] = useState(snapshot.preferences);
  const [delay, setDelay] = useState(String(preferences.scrollDelay / 1000));
  const current = useRef(preferences);
  const write = useRef(Promise.resolve());
  const t = words(preferences.locale);
  const pl = preferences.locale === 'pl';

  function change<K extends keyof CapturePreferences>(key: K, value: CapturePreferences[K]) {
    const next = {...current.current, [key]: value};
    current.current = next;
    setPreferences(next);
    onError('');
    write.current = write.current.then(async () => {
      try {
        const saved = await send<CapturePreferences>('preferences', {value: next});
        if (current.current === next) {
          current.current = saved;
          setPreferences(saved);
        }
      } catch (error) {
        onError(error instanceof Error ? error.message : String(error));
      }
    });
    return write.current;
  }

  async function enable(all: boolean) {
    try {
      const source = all ? null : httpUrl((await ext.tabs.query({active: true, currentWindow: true}))[0]?.url ?? '');
      if (!all && !source) throw new Error(pl ? 'Otwórz stronę i włącz przeciąganie z menu rozszerzenia.' : 'Open a website and enable drag from the extension menu.');
      if (!await ext.permissions.request({origins: source ? [permissionPattern(source)] : ['http://*/*', 'https://*/*']})) {
        throw new Error(t.permission);
      }
      await send('quick.enable', {all});
    } catch (error) {onError(error instanceof Error ? error.message : String(error));}
  }

  function debug() {
    const {excludedHosts, excludedUrls, ...options} = current.current;
    const data = {
      options,
      excludedSites: excludedHosts.length,
      excludedPages: excludedUrls.length,
      connections: snapshot.connections.length,
      jobs: snapshot.jobs.map(({state, size, attempts}) => ({state, size, attempts})),
    };
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'flex-capture-diagnostics.json';
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  return <div className="fc-preferences">
    <Section icon={FolderDownloadIcon} title={t.operations}>
      <div className="fc-settings-box">
        <div className="fc-drag-choices" role="group" aria-label={t.drag}>
          {(['popup', 'dock', 'off'] as const).map(mode => <Button
            key={mode} className="fc-drag-choice" variant="ghost" aria-pressed={preferences.quickMode === mode}
            onPress={() => void change('quickMode', mode)}
          >
            <span className="fc-drag-choice-picture" aria-hidden="true"><span className={`fc-drag-choice-frame is-${mode}`}><i/></span></span>
            <span>{t[mode]}</span>
          </Button>)}
        </div>
        <SwitchRow label={t.ask} value={preferences.askDestination} onChange={value => void change('askDestination', value)}
          description={pl ? 'Wybierz folder i dodaj tagi przed zapisaniem.' : 'Choose a folder and add tags before saving.'}/>
        <SwitchRow label={pl ? 'Pobieraj przez przeglądarkę' : 'Download through the browser'}
          description={pl ? 'Używa sesji przeglądarki do pobierania plików z bieżącej witryny.' : 'Use the browser session to download files from the current website.'}
          value={preferences.browserSession} onChange={value => void change('browserSession', value)}/>
      </div>
    </Section>

    <Section icon={CropIcon} title={t.screenshots}>
      <div className="fc-settings-box">
        <div className="fc-preference-row fc-preference-inline">
          <span>{t.format}</span>
          <div className="fc-format-choices" role="group" aria-label={t.format}>
            {(['png', 'jpeg'] as const).map(format => <Button key={format} size="sm" variant="ghost"
              aria-pressed={preferences.screenshotFormat === format} onPress={() => void change('screenshotFormat', format)}>
              {format.toUpperCase()}
            </Button>)}
          </div>
        </div>
        <SwitchRow label={t.retina} value={preferences.retina} onChange={value => void change('retina', value)}/>
        <div className="fc-preference-row fc-preference-inline">
          <span>{pl ? 'Opóźnienie przewijania' : 'Scroll delay'}</span>
          <label className="fc-delay">
            <Input aria-label={pl ? 'Opóźnienie w sekundach' : 'Scroll delay in seconds'} inputMode="decimal" variant="secondary"
              value={delay} onChange={event => setDelay(event.target.value)}
              onKeyDown={event => {if (event.key === 'Enter') event.currentTarget.blur();}}
              onBlur={() => {
                const seconds = Number(delay.replace(',', '.'));
                const next = Number.isFinite(seconds) && delay.trim() ? Math.max(0.5, Math.min(3, seconds)) : current.current.scrollDelay / 1000;
                setDelay(String(next));
                if (next * 1000 !== current.current.scrollDelay) void change('scrollDelay', Math.round(next * 1000));
              }}/>
            <span>{pl ? 's' : 'sec'}</span>
          </label>
        </div>
      </div>
    </Section>

    <Section icon={CommandIcon} title={t.shortcuts}>
      <div className="fc-settings-box">
        {shortcutActions.map(action => <div className="fc-preference-row fc-shortcut" key={action}>
          <span>{t[action]}</span>
          <Input aria-label={t[action]} variant="secondary" readOnly value={preferences.shortcuts[action]} placeholder="—"
            onKeyDown={event => {
              if (event.key === 'Tab' || event.key === 'Escape') return;
              event.preventDefault();
              const key = ['Backspace', 'Delete'].includes(event.key) ? '' : shortcutFromEvent(event.nativeEvent);
              if (!key && !['Backspace', 'Delete'].includes(event.key)) return;
              const shortcuts = current.current.shortcuts;
              if (key && shortcutActions.some(other => other !== action && shortcuts[other] === key)) {
                onError(pl ? 'Ten skrót jest już przypisany.' : 'This shortcut is already assigned.');
                return;
              }
              void change('shortcuts', {...shortcuts, [action]: key});
            }}/>
        </div>)}
      </div>
    </Section>

    <Section icon={CodeIcon} title={pl ? 'Własne atrybuty zapisu' : 'Custom collection attributes'}>
      <p>{pl ? 'Określ źródło, nazwę i tagi obrazów za pomocą atrybutów HTML.' : 'Set image sources, titles and tags with HTML attributes.'}</p>
      <details className="fc-preference-details">
        <summary>{pl ? 'Pokaż obsługiwane atrybuty' : 'View supported attributes'}</summary>
        <code>data-flex-src, data-flex-name, data-flex-url, data-flex-description, data-flex-tags, data-flex-width, data-flex-height</code>
      </details>
    </Section>

    <Section icon={Settings02Icon} title={t.appearance}>
      <div className="fc-settings-box fc-preference-form">
        <Choice label={t.theme} value={preferences.theme} onChange={value => void change('theme', value as CapturePreferences['theme'])}
          items={(['light', 'dark', 'system'] as const).map(id => ({id, name: t[id]}))}/>
        <Choice label={t.language} value={preferences.locale} onChange={value => void change('locale', value === 'pl' ? 'pl' : 'en')}
          items={[{id: 'pl', name: 'Polski'}, {id: 'en', name: 'English'}]}/>
      </div>
    </Section>

    <Section icon={FolderDownloadIcon} title={pl ? 'Witryny i połączenia' : 'Websites and connections'}>
      <div className="fc-settings-box">
        <SwitchRow label={t.altClick} value={preferences.altRightClick} onChange={value => void change('altRightClick', value)}/>
        <SwitchRow label={t.high} value={preferences.highResolution} onChange={value => void change('highResolution', value)}/>
        <div className="fc-preference-row fc-preference-form">
          <Button variant="secondary" size="sm" onPress={() => void enable(false)}>{pl ? 'Zezwól na tej witrynie' : 'Allow on this website'}</Button>
          <Button variant="secondary" size="sm" onPress={() => void enable(true)}>{pl ? 'Zezwól na wszystkich witrynach' : 'Allow on all websites'}</Button>
          <Button variant="secondary" size="sm" onPress={onConnect}>{t.connect}</Button>
        </div>
      </div>
      <details className="fc-preference-details">
        <summary>{pl ? 'Opcje zaawansowane' : 'Advanced options'}</summary>
        <form className="fc-preference-form" onSubmit={event => {
          event.preventDefault();
          const values = new FormData(event.currentTarget);
          void change('maxDownloadBytes', Number(values.get('bytes')) * 1048576);
          void change('concurrency', Number(values.get('concurrency')));
          void change('excludedUrls', String(values.get('urls')).split(/\s+/).filter(Boolean));
          void change('excludedHosts', String(values.get('hosts')).split(/[\s,]+/).filter(Boolean));
        }}>
          <label>{t.downloadLimit}<Input name="bytes" type="number" min={1} max={128} variant="secondary" defaultValue={String(preferences.maxDownloadBytes / 1048576)}/></label>
          <label>{t.concurrency}<Input name="concurrency" type="number" min={1} max={3} variant="secondary" defaultValue={String(preferences.concurrency)}/></label>
          <label>{pl ? 'Wyłączone adresy stron' : 'Excluded page URLs'}<Input name="urls" variant="secondary" defaultValue={preferences.excludedUrls.join(' ')}/></label>
          <label>{t.exclude}<Input name="hosts" variant="secondary" defaultValue={preferences.excludedHosts.join(', ')}/></label>
          <Button size="sm" variant="secondary" type="submit">{t.save}</Button>
        </form>
      </details>
    </Section>

    <Section icon={Bug01Icon} title={t.support}>
      <Button className="fc-debug-button" variant="secondary" size="sm" onPress={debug}><Icon icon={Bug01Icon} size={16}/>{t.debug}</Button>
      <p>{t.privacy}</p>
    </Section>
  </div>;
}
