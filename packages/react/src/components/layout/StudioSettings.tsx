import {ShortcutSettings,type ShortcutSettingsProps} from '../../shortcuts/ShortcutSettings';
import {TimeZoneSelect} from './TimeZoneSelect';
import {browserTimeZone} from './TimePreferences';
import {BrowserPushSwitch} from '../management/BrowserPushSwitch';
import type {BrowserPushControl} from '../management/useBrowserPush';
import {pushEvents, type PushEvent} from '../../browser-push';
import {SourceImportsPanel} from './SourceImportsPanel';
import {Button, Checkbox, Input, Modal, Select, Label, ListBox, Spinner, Switch} from '@heroui/react';
import {KeyboardIcon, Settings02Icon, Upload04Icon, PaintBoardIcon, Image01Icon, Notification01Icon} from '@hugeicons/core-free-icons';
import {useEffect, useId, useRef, useState} from 'react';
import type {StudioTransport, WorkspaceSettings} from '../../contracts';
import {Icon, errorText, type Messages} from '../../ui';

type Props = {
  shortcuts: ShortcutSettingsProps | null;
  autoTimezone:boolean;timezone:string;onTimezone:(automatic:boolean,zone:string)=>void;
  workspace: string | undefined;
  canSources?:boolean;
  canViewWorkspaceSettings: boolean;
  onSourceImported:(workspace:string)=>void;
  transport: StudioTransport;
  portal: HTMLDivElement;
  t: Messages;
  locale: string;languages:{id:string;label:string}[];
  theme: 'light' | 'dark';
  onTheme: (theme: 'light' | 'dark') => void;
  notifications: boolean;
  browserPush: BrowserPushControl;
  onLocale: (locale: string) => void;
  onNotifications: (enabled: boolean) => void;
  onSaved: (workspace: string, settings: WorkspaceSettings) => void;
  onClose: () => void;
};

export function StudioSettings({shortcuts,autoTimezone,timezone,onTimezone,browserPush, canViewWorkspaceSettings, workspace,canSources,onSourceImported, transport, portal, t, locale, languages, theme, onTheme, notifications, onLocale, onNotifications, onSaved, onClose}: Props) {
  const titleId = useId();
  const eventLabels: Record<PushEvent, string> = {
    mentioned: t.pushMentioned, uploaded: t.pushUploaded, guest_uploaded: t.pushGuestUploaded,
    processed: t.pushProcessed, trashed: t.pushTrashed, restored: t.pushRestored, purged: t.pushPurged,
  };
  const [selectPortal, setSelectPortal] = useState<HTMLDivElement | null>(null);
  const [section, setSection] = useState<'general' | 'appearance' | 'uploads' | 'processing' | 'sources' | 'notifications' | 'shortcuts'>('general');
  const [settings, setSettings] = useState<WorkspaceSettings | null>(null);
  const [extensions, setExtensions] = useState<string[]>([]);
  const [chunks, setChunks] = useState(false);
  const [maxMiB, setMaxMiB] = useState('');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [saving, setSaving] = useState(false);
  const request = useRef<AbortController | null>(null);
  const groupLabels: Record<string, string> = {
    code: t.codeFiles, document: t.documents, spreadsheet: t.spreadsheets, presentation: t.presentations,
    image: t.images, video: t.videos, audio: t.audio, archive: t.archives, font: t.fonts, other: t.otherFiles,
  };
  const canLoad = Boolean(canViewWorkspaceSettings && workspace && transport.settings);
  const fill = (value: WorkspaceSettings) => {
    setSettings(value);
    setExtensions(value.extensions);
    setChunks(value.chunks);
    setMaxMiB(value.chunkMaxMiB == null ? '' : String(value.chunkMaxMiB));
  };

  useEffect(() => {
    if (!canLoad || !workspace || !transport.settings) return;
    const controller = new AbortController();
    request.current?.abort();
    setSaving(false);
    setSettings(null);
    setError('');
    void transport.settings(workspace, controller.signal).then(value => {
      if (!controller.signal.aborted) fill(value);
    }).catch(reason => {
      if (!controller.signal.aborted) setError(errorText(reason));
    });
    return () => controller.abort();
  }, [workspace, transport, attempt, canLoad]);
  useEffect(() => () => request.current?.abort(), [workspace]);

  const canEdit = Boolean(settings?.canManage && transport.updateSettings);
  const transferPolicy = settings?.transferPolicy;
  const chunkMaxMiB = maxMiB === '' ? null : Number(maxMiB);
  const validLimit = chunkMaxMiB === null || (Number.isInteger(chunkMaxMiB) && chunkMaxMiB >= 1 && chunkMaxMiB <= (transferPolicy?.serverMaxMiB ?? 0));
  const dirty = settings && (chunks !== settings.chunks || chunkMaxMiB !== (settings.chunkMaxMiB ?? null) || extensions.length !== settings.extensions.length
    || extensions.some(extension => !settings.extensions.includes(extension)));
  const toggle = (items: string[], enabled: boolean) => setExtensions(previous =>
    enabled ? [...new Set([...previous, ...items])] : previous.filter(item => !items.includes(item))
  );
  const save = () => {
    if (!workspace || !settings || !transport.updateSettings || !canEdit || !dirty || !validLimit || saving) return;
    const controller = new AbortController();
    request.current = controller;
    setSaving(true);
    setError('');
    void transport.updateSettings(workspace, {revision: settings.revision, extensions, chunks, ...(transferPolicy ? {chunkMaxMiB} : {})}, controller.signal)
      .then(value => {
        if (!controller.signal.aborted) {
          fill(value);
          onSaved(workspace, value);
        }
      })
      .catch(reason => {if (!controller.signal.aborted) setError(errorText(reason));})
      .finally(() => {if (!controller.signal.aborted) setSaving(false);});
  };

  const sections = [
    {id: 'general', label: t.generalSettings, icon: Settings02Icon, group: t.personalSettings},
    {id: 'appearance', label: t.appearance, icon: PaintBoardIcon, group: null},
    ...(shortcuts ? [{id:'shortcuts',label:t.shortcuts,icon:KeyboardIcon,group:null}] as const:[]),
    {id: 'notifications', label: t.notificationSettings, icon: Notification01Icon, group: null},
    ...(canLoad ? [
      {id: 'uploads', label: t.uploadSettings, icon: Upload04Icon, group: t.settingsWorkspaceGroup},
      {id: 'processing', label: t.processingSettings, icon: Image01Icon, group: null},
    ] as const : []),
    ...(canSources && transport.sourceImports ? [{id:'sources',label:t.sourceImports,icon:Upload04Icon,group:canLoad ? null : t.settingsWorkspaceGroup}] as const:[]),
  ] as const;
  const current = sections.find(item => item.id === section) ?? sections[0];
  const errorPanel = error && <div className="fms-error fms-settings-row" role="alert">
    <span>{error}</span><Button size="sm" variant="tertiary" onPress={() => {if (settings && canEdit && dirty) save(); else setAttempt(value => value + 1);}}>{t.retry}</Button>
  </div>;

  return <Modal><Modal.Backdrop className="fms-settings-backdrop" isOpen isDismissable={!saving} isKeyboardDismissDisabled={saving}
    onOpenChange={open => {if (!open && !saving) onClose();}} UNSTABLE_portalContainer={portal}>
    <Modal.Container className="fms-settings-container" size="lg" placement="center"><Modal.Dialog className="fms-settings-dialog" aria-labelledby={titleId}>
      <nav className="fms-settings-nav" aria-label={t.settings}>
        <div className="fms-settings-nav-title" id={titleId}>{t.settings}</div>
        {sections.map(item => <div className="fms-settings-nav-item" key={item.id}>
          {item.group && <div className="fms-settings-nav-group">{item.group}</div>}
          <Button variant="ghost" size="sm" aria-current={current.id === item.id ? 'page' : false}
          isDisabled={saving} onPress={() => setSection(item.id)}><Icon icon={item.icon} size={18}/><span>{item.label}</span></Button></div>)}
      </nav>
      <section className="fms-settings-content">
        <header className="fms-settings-header">
          <h2>{current.label}</h2><Modal.CloseTrigger isDisabled={saving} className="fms-settings-close"/>
        </header>
        <div className="fms-settings-scroll" key={current.id}>
          {current.id === 'shortcuts' && shortcuts && <ShortcutSettings {...shortcuts}/>}
          {current.id === 'sources' && workspace && <SourceImportsPanel onImported={onSourceImported} workspace={workspace} transport={transport} t={t}/>}
          {current.id === 'general' && <>
            <p className="fms-settings-note">{t.deviceSettingsHint}</p>
            <h3 className="fms-settings-group-title">{t.preferences}</h3>
            <div className="fms-settings-group">
              <div className="fms-settings-row">
                <div><strong>{t.language}</strong><p>{t.languageHint}</p></div>
                <div className="fms-settings-control"><Select aria-label={t.language} variant="secondary" value={locale} onChange={key => {if (typeof key==='string'&&languages.some(item=>item.id===key)) onLocale(key);}}>
                  <Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
                  <Select.Popover {...(selectPortal ? {UNSTABLE_portalContainer: selectPortal} : {})}><ListBox>
                    {languages.map(item=><ListBox.Item key={item.id} id={item.id} textValue={item.label}><Label>{item.label}</Label><ListBox.ItemIndicator/></ListBox.Item>)}
                  </ListBox></Select.Popover>
                </Select></div>
              </div>
              <div className="fms-settings-row"><div><strong>{locale==='pl'?'Automatyczna strefa czasowa':'Automatic timezone'}</strong><p>{browserTimeZone()}</p></div><Switch isSelected={autoTimezone} onChange={value=>onTimezone(value,timezone)} aria-label={locale==='pl'?'Automatyczna strefa czasowa':'Automatic timezone'}><Switch.Content><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch></div>
              {!autoTimezone&&<div className="fms-settings-timezone"><TimeZoneSelect value={timezone} onChange={zone=>onTimezone(false,zone)} portal={selectPortal??portal} pl={locale==='pl'}/></div>}
            </div>
          </>}
          {current.id === 'notifications' && <>
            <h3 className="fms-settings-group-title">{t.notificationSettings}</h3>
            <div className="fms-settings-group">
              <div className="fms-settings-row">
                <div><strong>{t.uploadNotifications}</strong><p>{t.uploadNotificationsHint}</p></div>
                <Switch isSelected={notifications} onChange={onNotifications} aria-label={t.uploadNotifications}>
                  <Switch.Content><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content>
                </Switch>
              </div>
            </div>
            <p className="fms-settings-note">{t.deviceSettingsHint}</p>
            {workspace && transport.management?.browserPush && <>
              <div className="fms-settings-group">
                <div className="fms-settings-row">
                  <div><strong>{t.desktopNotifications}</strong><p>{browserPush.hint}</p></div>
                  <BrowserPushSwitch hideLabel push={browserPush} t={t}/>
                </div>
              </div>
              {browserPush.error && <p className="fms-error" role="alert">{browserPush.error}</p>}
              <h3 className="fms-settings-group-title">{t.pushTypes}</h3>
              <p className="fms-settings-note">{t.pushTypesHint}</p>
              <div className="fms-settings-group">{pushEvents.map(event => <div className="fms-settings-row" key={event}>
                <Checkbox isSelected={browserPush.settings?.events.includes(event) ?? false}
                  isDisabled={!browserPush.settings?.available || browserPush.busy}
                  onChange={selected => {
                    const events = browserPush.settings?.events ?? [];
                    void browserPush.changeEvents(selected ? [...events, event] : events.filter(item => item !== event));
                  }}>
                  <Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Label>{eventLabels[event]}</Label></Checkbox.Content>
                </Checkbox>
              </div>)}</div>
            </>}
          </>}
          {current.id === 'appearance' && <>
            <p className="fms-settings-note">{t.deviceSettingsHint}</p>
            <h3 className="fms-settings-group-title">{t.preferences}</h3>
            <div className="fms-settings-group"><div className="fms-settings-row">
              <div><strong>{t.appearance}</strong><p>{t.appearanceHint}</p></div>
              <div className="fms-settings-control"><Select aria-label={t.appearance} variant="secondary" value={theme} onChange={key => {if (key === 'light' || key === 'dark') onTheme(key);}}>
                <Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
                <Select.Popover {...(selectPortal ? {UNSTABLE_portalContainer: selectPortal} : {})}><ListBox>
                  <ListBox.Item id="light" textValue={t.lightMode}><Label>{t.lightMode}</Label><ListBox.ItemIndicator/></ListBox.Item>
                  <ListBox.Item id="dark" textValue={t.darkMode}><Label>{t.darkMode}</Label><ListBox.ItemIndicator/></ListBox.Item>
                </ListBox></Select.Popover>
              </Select></div>
            </div></div>
          </>}
          {current.id === 'uploads' && (settings ? <>
            <p className="fms-settings-note">{t.workspaceSettingsHint}</p>
            <h3 className="fms-settings-group-title">{t.transferSettings}</h3>
            <div className="fms-settings-group"><div className="fms-settings-row">
              <div><strong>{t.chunkUploads}</strong><p>{settings.chunksAvailable ? t.chunkUploadsHint : t.chunksDisabled}</p></div>
              <Switch isSelected={chunks} isDisabled={!canEdit || !settings.chunksAvailable || saving} onChange={setChunks} aria-label={t.chunkUploads}>
                <Switch.Content><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content>
              </Switch>
            </div>
              {chunks && transferPolicy && <>
                {transferPolicy.serverMaxMiB >= 1 && <div className="fms-settings-row">
                  <div><strong>{t.chunkLimit}</strong><p>{t.chunkLimitHint.replace('{limit}', String(transferPolicy.serverMaxMiB))}</p></div>
                  <div className="fms-settings-control"><Input type="number" variant="secondary" aria-label={t.chunkLimit}
                    min={1} max={transferPolicy.serverMaxMiB} step={1} placeholder={String(transferPolicy.serverMaxMiB)}
                    aria-invalid={!validLimit} disabled={!canEdit || saving} value={maxMiB} onChange={event => setMaxMiB(event.target.value)}/></div>
                </div>}
                <div className="fms-settings-row">
                  <div><strong>{t.chunkPartSize}</strong><p>{t.chunkPartHint.replace('{parts}', String(transferPolicy.maxParts)).replace('{concurrent}', String(transferPolicy.maxConcurrent))}</p></div>
                  <span className="fms-settings-value">{(transferPolicy.chunkBytes / 1048576).toLocaleString(locale)} MiB</span>
                </div>
                {transferPolicy.multipartThresholdBytes !== null && <div className="fms-settings-row">
                  <strong>{t.multipartThreshold}</strong><span className="fms-settings-value">{(transferPolicy.multipartThresholdBytes / 1048576).toLocaleString(locale)} MiB</span>
                </div>}
              </>}
            </div>
            <h3 className="fms-settings-group-title">{t.allowedExtensions}</h3>
            <p className="fms-settings-note">{canEdit ? t.uploadTypesHint : t.uploadSettingsReadOnly}</p>
            <div className="fms-settings-group fms-settings-types">{Object.entries(settings.groups).filter(([, items]) => items.length > 0).map(([category, items]) => {
              const count = items.filter(item => extensions.includes(item)).length;
              return <details key={category}>
                <summary><span>{groupLabels[category] ?? category}</span><span>{count}/{items.length}</span></summary>
                <div className="fms-settings-extensions">
                  <Checkbox isSelected={count === items.length} isIndeterminate={count > 0 && count < items.length}
                    isDisabled={!canEdit || saving} onChange={selected => toggle(items, selected)}>
                    <Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Label>{t.selectAll}</Label></Checkbox.Content>
                  </Checkbox>
                  {items.map(item => <Checkbox key={item} isSelected={extensions.includes(item)} isDisabled={!canEdit || saving}
                    onChange={selected => toggle([item], selected)}>
                    <Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Label>.{item}</Label></Checkbox.Content>
                  </Checkbox>)}
                </div>
              </details>;
            })}</div>
          </> : !error && <Spinner aria-label={t.loading}/>)}
          {current.id === 'processing' && (settings?.processing ? <>
            <p className="fms-settings-note">{t.serverProcessingHint}</p>
            <h3 className="fms-settings-group-title">{t.imageRenditions}</h3>
            <div className="fms-settings-group">{settings.processing.images.map(preset => <div className="fms-settings-row" key={preset.name}>
              <div><strong>{preset.name}</strong><p>{preset.width} × {preset.height} · {t.quality} {preset.quality} · {preset.stripMetadata === false ? t.metadataRetained : t.metadataRemoved}</p></div>
              <span className="fms-settings-value">{preset.format.toUpperCase()}</span>
            </div>)}</div>
            <h3 className="fms-settings-group-title">{t.videoSettings}</h3>
            <div className="fms-settings-group">
              <div className="fms-settings-row"><strong>{t.videoCompression}</strong><span>{settings.processing.video.compress ? t.enabled : t.disabled}</span></div>
              <div className="fms-settings-row"><strong>{t.videoPoster}</strong><span>{settings.processing.video.poster?.toUpperCase() ?? t.disabled}</span></div>
            </div>
          </> : !error && (settings ? <p className="fms-settings-note">{t.serverProcessingHint}</p> : <Spinner aria-label={t.loading}/>))}
          {(current.id === 'uploads' || current.id === 'processing') && errorPanel}
        </div>
        {current.id === 'uploads' && canEdit && <footer className="fms-settings-footer">
          <Button size="sm" isDisabled={saving || !dirty || !validLimit} onPress={save}>{saving ? <Spinner size="sm"/> : t.save}</Button>
        </footer>}
      </section>
      <div ref={setSelectPortal} className="fms-settings-select-portal"/>
    </Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}
