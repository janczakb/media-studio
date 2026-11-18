import {Button,Tabs} from '@heroui/react';
import {useState} from 'react';
import type {Messages} from '../ui';
import {ShortcutRecorder,ShortcutList,type RecorderText,type ShortcutListGroup} from './ArcShortcutRecorder';
import {normalizeShortcut,resolveShortcutBindings,shortcutDefaults,shortcutSignature,type ShortcutBindings,type ShortcutCommand,type ShortcutRegistry} from './registry';
export interface ShortcutSettingsProps {registry:ShortcutRegistry|null;bindings:Record<ShortcutCommand,string|null>;defaults?:ShortcutBindings;overrides:ShortcutBindings;customizable:boolean;onChange:(bindings:ShortcutBindings)=>void;t:Messages}
const labels:Record<ShortcutCommand,keyof Messages>={search:'searchLibrary',upload:'upload',newFolder:'newFolder',settings:'settings',selectAll:'selectAll',clearSelection:'clearSelection',copyImage:'shortcutCopyImage',pasteFiles:'shortcutPasteFiles'};
const groups: {label:keyof Messages;ids:ShortcutCommand[]}[]=[{label:'shortcutWorkspace',ids:['search','upload','newFolder','settings']},{label:'shortcutSelection',ids:['selectAll','clearSelection']},{label:'shortcutClipboard',ids:['copyImage','pasteFiles']}];
export function ShortcutSettings({registry,bindings,defaults={},overrides,customizable,onChange,t}:ShortcutSettingsProps){
  const [platform,setPlatform]=useState<'mac'|'other'>(()=>registry?.mac?'mac':'other');
  const baseline=resolveShortcutBindings(defaults,{},registry?.mac??false);
  const text:RecorderText={record:t.shortcutRecord,press:t.shortcutListening,recording:t.shortcutRecordingAnnouncement,cancelled:t.shortcutCancelled,cleared:t.shortcutCleared,set:t.shortcutSet,hint:t.shortcutRecordingHint,includeMac:t.shortcutIncludeMac,includeOther:t.shortcutIncludeOther,reserved:t.shortcutReservedFor,used:t.shortcutUsedBy,useAnyway:t.shortcutUseAnyway,cancel:t.cancelUpload,clear:t.shortcutClear,reset:t.shortcutReset,none:t.disabled};
  const list:ShortcutListGroup[]=groups.map(group=>({label:t[group.label],items:group.ids.filter(id=>bindings[id]).map(id=>({label:t[labels[id]],shortcut:bindings[id]!,keywords:id}))}));
  return <div className="fms-shortcuts-settings">
    <div className="fms-shortcuts-heading"><h3 className="fms-shortcuts-title">{t.shortcuts}</h3><Tabs variant="secondary" selectedKey={platform} onSelectionChange={key=>{if(key==='mac'||key==='other')setPlatform(key);}}><Tabs.ListContainer><Tabs.List aria-label={t.shortcutPlatform}><Tabs.Tab id="mac">Mac<Tabs.Indicator/></Tabs.Tab><Tabs.Tab id="other">Windows<Tabs.Indicator/></Tabs.Tab></Tabs.List></Tabs.ListContainer></Tabs></div>
    <p className="fms-settings-note">{t.shortcutsHint}</p>
    <div className="fms-shortcuts-recorders">{(Object.keys(shortcutDefaults) as ShortcutCommand[]).map(id=><ShortcutRecorder key={id}
      label={t[labels[id]]} value={bindings[id]} resetValue={baseline[id]} platform={platform} text={text}
      requireModifier={id!=='clearSelection'} disabled={!registry?.enabled||!customizable}
      bindings={(Object.keys(shortcutDefaults) as ShortcutCommand[]).filter(other=>other!==id&&bindings[other]).map(other=>({shortcut:bindings[other]!,label:t[labels[other]]}))}
      onValueChange={value=>{
        const binding=value===null?null:normalizeShortcut(value);if(value!==null&&!binding)return;
        const next={...overrides,[id]:binding};
        // "Use anyway" transfers the chord atomically instead of silently disabling a different action.
        if(binding)for(const other of Object.keys(shortcutDefaults) as ShortcutCommand[]){if(other!==id&&bindings[other]&&shortcutSignature(binding,registry?.mac??false)===shortcutSignature(bindings[other]!,registry?.mac??false))next[other]=null;}
        onChange(next);
      }}/>)}</div>
    {!customizable&&<p className="fms-settings-note">{t.shortcutManaged}</p>}
    <hr className="fms-shortcuts-divider"/>
    <ShortcutList groups={list} platform={platform} label={t.shortcuts} searchPlaceholder={t.shortcutSearch} emptyLabel={t.shortcutEmpty} matchesLabel={t.shortcutMatches}/>
    {customizable&&<Button size="sm" variant="secondary" onPress={()=>onChange({})}>{t.shortcutResetAll}</Button>}
  </div>;
}
