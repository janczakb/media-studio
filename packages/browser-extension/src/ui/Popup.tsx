import {consumePopupRoute} from '../core/popup-route';
import {Button, Dropdown, Spinner} from '@heroui/react';
import {useEffect, useState} from 'react';
import {
  ArrowLeft01Icon, ArrowRight01Icon, ArrowDown01Icon, Camera01Icon, CropIcon,
  Cursor02Icon, Folder01Icon, Image01Icon, Link01Icon, Settings02Icon, ViewIcon,
} from '@hugeicons/core-free-icons';
import {ext, send} from '../core/webextension';
import {httpUrl, permissionPattern} from '../core/urls';
import type {ShortcutAction} from '../core/shortcuts';
import {useSnapshot, words, Icon} from './shared';
import {Connect} from './Connect';
import {Preferences} from './Preferences';

export function Popup() {
  const {snapshot, error, setError} = useSnapshot();
  const [view, setView] = useState<'menu' | 'settings' | 'connect'>(new URL(location.href).searchParams.has('settings')?'settings':new URL(location.href).searchParams.has('connect')?'connect':'menu');
  const [routeReady, setRouteReady] = useState(false);
  useEffect(() => {
    let active = true;
    void consumePopupRoute().then(route => {if (active && route) setView(route);}, error => {if (active) setError(String(error));}).finally(() => {if (active) setRouteReady(true);});
    return () => {active = false;};
  }, []);
  const [busy, setBusy] = useState(false);
  const [source, setSource] = useState('');
  const [allowed, setAllowed] = useState(false);
  const t = words(snapshot?.preferences.locale ?? 'en');
  const pl = snapshot?.preferences.locale === 'pl';
  const p = snapshot?.preferences;

  useEffect(() => {
    void ext.tabs.query({active: true, currentWindow: true}).then(tabs => {
      setSource(httpUrl(tabs[0]?.url ?? '') ?? '');
    }).catch(() => {});
  }, []);

  useEffect(() => {
    let active = true;
    if (source) void ext.permissions.contains({origins: [permissionPattern(source)]})
      .then(granted => {if (active) setAllowed(granted);}).catch(() => {});
    return () => {active = false;};
  }, [source]);

  useEffect(() => {
    const media = matchMedia('(prefers-color-scheme: dark)');
    const update = () => {
      document.documentElement.classList.toggle('dark', p?.theme === 'dark' || (p?.theme !== 'light' && media.matches));
    };
    update();
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, [p?.theme]);

  useEffect(() => {
    document.body.dataset.captureView = view;
    return () => {delete document.body.dataset.captureView;};
  }, [view]);

  if (!snapshot || !p || !routeReady) return <div className="fc-loading"><Spinner size="sm"/>{error}</div>;

  const host = source ? new URL(source).hostname : '';
  const showBoard = /(?:^|\.)(?:pinterest\.[a-z.]+|huaban\.com|dribbble\.com|meiye\.art|rednote\.com|xiaohongshu\.com|x\.com|twitter\.com|gameui\.net)$/.test(host);
  const excluded = p.excludedUrls.includes(source) || p.excludedHosts.includes(host);
  const enabled = allowed && p.quickMode !== 'off' && !excluded;

  async function run(type: string, data: Record<string, unknown> = {}) {
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await send(type, data);
      window.close();
    } catch (error) {setError(error instanceof Error ? error.message : String(error));}
    finally {setBusy(false);}
  }

  function bookmark() {
    const connection = snapshot?.connections[0];
    if (!connection) setView('connect');
    else void run('bookmark', {connectionId: connection.id});
  }

  async function drag(action: string) {
    if (!p || !source) return;
    const next = {
      ...p,
      excludedUrls: p.excludedUrls.filter(url => url !== source),
      excludedHosts: p.excludedHosts.filter(value => value !== host),
    };
    try {
      if (action === 'enable') {
        if (!await ext.permissions.request({origins: [permissionPattern(source)]})) throw new Error(t.permission);
        if (next.quickMode === 'off') next.quickMode = 'popup';
      } else if (action === 'page') next.excludedUrls.push(source);
      else if (action === 'host') next.excludedHosts.push(host);
      await send('preferences', {value: next});
      if (action === 'enable') {
        await send('quick.enable');
        setAllowed(true);
      }
      setError('');
    } catch (error) {setError(error instanceof Error ? error.message : String(error));}
  }

  function row(label: string, icon: typeof Link01Icon, onPress: () => void, shortcut?: ShortcutAction) {
    return <Button className="fc-menu-row" variant="ghost" isDisabled={busy} onPress={onPress}>
      <Icon icon={icon} size={16}/><span>{label}</span>
      {shortcut && p?.shortcuts[shortcut] && <span className="fc-menu-keys" aria-hidden="true">
        {p.shortcuts[shortcut].split(' + ').map(key => <kbd key={key}>{key === 'Alt' && /Mac/.test(navigator.platform) ? '⌥' : key}</kbd>)}
      </span>}
    </Button>;
  }

  if (view !== 'menu') return <main className="fc-popup-settings">
    <header className="fc-settings-header">
      <Button isIconOnly aria-label={t.back} size="sm" variant="ghost" onPress={() => {setView('menu');setError('');}}><Icon icon={ArrowLeft01Icon} size={16}/></Button>
      {view === 'settings' ? t.settings : t.connect}
    </header>
    {error && <p className="fc-alert" role="alert">{error}</p>}
    {view === 'settings' ? <Preferences snapshot={snapshot} onError={setError} onConnect={() => setView('connect')}/> : <Connect snapshot={snapshot} onError={setError}/>}
  </main>;

  return <main className="fc-menu" aria-label={t.title}>
    {showBoard && row(pl ? 'Zapisz tablicę' : 'Save board’s images', Folder01Icon, () => void run('board'))}
    {row(t.bookmark, Link01Icon, bookmark, 'bookmark')}
    {row(t.collect, Image01Icon, () => void run('collect'), 'collect')}
    <hr/>
    {row(t.area, CropIcon, () => void run('capture', {mode: 'area'}), 'area')}
    {row(t.visible, ViewIcon, () => void run('capture', {mode: 'visible'}), 'visible')}
    {row(t.full, Camera01Icon, () => void run('capture', {mode: 'full'}), 'full')}
    <hr/>
    <div className="fc-menu-drag"><Icon icon={Cursor02Icon} size={16}/><span>{t.drag}</span>
      <Dropdown>
        <Button className="fc-menu-status" size="sm" variant="ghost" isDisabled={!source} aria-label={t.drag}>{enabled ? (pl ? 'Włączone' : 'Enabled') : t.off}<Icon icon={ArrowDown01Icon} size={12}/></Button>
        <Dropdown.Popover placement="bottom end">
          <Dropdown.Menu aria-label={t.drag} onAction={key => void drag(String(key))}>
            <Dropdown.Item id="enable">{pl ? 'Włącz w tej witrynie' : 'Enable on this website'}</Dropdown.Item>
            <Dropdown.Item id="page">{pl ? 'Wyłącz na tej stronie' : 'Disable on this page'}</Dropdown.Item>
            <Dropdown.Item id="host">{pl ? 'Wyłącz w tej domenie' : 'Disable on this domain'}</Dropdown.Item>
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
    </div>
    <hr/>
    <Button className="fc-menu-row" variant="ghost" onPress={() => setView('settings')}><Icon icon={Settings02Icon} size={16}/><span>{t.settings}</span><Icon icon={ArrowRight01Icon} size={14}/></Button>
    {error && <p className="fc-alert" role="alert">{error}</p>}
  </main>;
}
