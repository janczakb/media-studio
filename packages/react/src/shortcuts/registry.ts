/** Stable command IDs are independent of translated labels and keyboard layout. */
export const shortcutDefaults = {
  search: 'Mod+K', upload: 'Mod+Shift+U', newFolder: 'Alt+Shift+N',
  settings: 'Alt+Shift+S', selectAll: 'Mod+A', clearSelection: 'Escape',
  copyImage: 'Mod+C', pasteFiles: 'Mod+V',
} as const;
export type ShortcutCommand = keyof typeof shortcutDefaults;
export type ShortcutBindings = Partial<Record<ShortcutCommand, string | null>>;
export interface ShortcutOptions {
  enabled?: boolean;
  customizable?: boolean;
  bindings?: ShortcutBindings;
  clipboard?: false | {paste?: boolean; copyImages?: boolean; maxFiles?: number; maxBytes?: number; maxPixels?: number};
}
const modifiers = ['Mod', 'Ctrl', 'Meta', 'Alt', 'Shift'];
const aliases: Record<string, string> = {',':'Comma','.':'Period','/':'Slash','\\':'Backslash','-':'Minus','=':'Equal',';':'Semicolon',"'":'Quote','[':'BracketLeft',']':'BracketRight','`':'Backquote',up:'ArrowUp',down:'ArrowDown',left:'ArrowLeft',right:'ArrowRight',arrowup:'ArrowUp',arrowdown:'ArrowDown',arrowleft:'ArrowLeft',arrowright:'ArrowRight',home:'Home',end:'End',pageup:'PageUp',pagedown:'PageDown',tab:'Tab',control:'Ctrl',ctrl:'Ctrl',meta:'Meta',command:'Meta',cmd:'Meta',mod:'Mod',option:'Alt',alt:'Alt',shift:'Shift',esc:'Escape',escape:'Escape',space:'Space',comma:'Comma',period:'Period',slash:'Slash',backslash:'Backslash',minus:'Minus',equal:'Equal',semicolon:'Semicolon',quote:'Quote',bracketleft:'BracketLeft',bracketright:'BracketRight',backquote:'Backquote',enter:'Enter',backspace:'Backspace',delete:'Delete'};
export function normalizeShortcut(value: unknown): string | null {
  if (typeof value !== 'string' || value.length > 80) return null;
  const tokens=value.split('+').map(part=>part.trim());
  if(tokens.some(part=>!part))return null;
  const converted=tokens.map(part=>aliases[part.toLowerCase()]??(/^[a-z0-9]$/i.test(part)?part.toUpperCase():/^f(?:[1-9]|1[0-2])$/i.test(part)?part.toUpperCase():''));
  const key=converted.pop();
  if(!key||modifiers.includes(key)||converted.some(part=>!modifiers.includes(part))||new Set(converted).size!==converted.length)return null;
  if(converted.includes('Mod')&&converted.includes('Meta'))return null;
  return [...modifiers.filter(part=>converted.includes(part)),key].join('+');
}
export function shortcutSignature(binding:string,mac:boolean):string {
  return [...new Set(binding.split('+').map(part=>part==='Mod'?(mac?'Meta':'Ctrl'):part))].sort().join('+');
}
export function eventShortcut(event:Pick<KeyboardEvent,'key'|'code'|'ctrlKey'|'metaKey'|'altKey'|'shiftKey'>):string|null {
  const key=event.code?.replace(/^(Key|Digit)/,'')||event.key;
  return normalizeShortcut([event.ctrlKey?'Ctrl':'',event.metaKey?'Meta':'',event.altKey?'Alt':'',event.shiftKey?'Shift':'',key].filter(Boolean).join('+'));
}
/** Identify browser/OS chords so the recorder can request explicit conflict override. */
export function reservedShortcut(binding:string,mac:boolean):boolean {
  const signature=shortcutSignature(binding,mac);
  return ['Mod+L','Mod+T','Mod+N','Mod+W','Mod+R','Mod+Shift+N','Mod+Shift+T','Mod+Q','Mod+Shift+W','Mod+Shift+R','Mod+Shift+I','Mod+Shift+J','Mod+Shift+C','Alt+F4','Alt+Tab','Ctrl+Alt+Delete','F5','F11','F12','Tab','Shift+Tab','Enter','Space'].some(value=>shortcutSignature(value,mac)===signature);
}
export function resolveShortcutBindings(defaults:ShortcutBindings={},overrides:ShortcutBindings={},mac=false):Record<ShortcutCommand,string|null> {
  const resolved={...shortcutDefaults} as Record<ShortcutCommand,string|null>;
  for(const values of [defaults,overrides])for(const id of Object.keys(shortcutDefaults) as ShortcutCommand[]){
    if(values[id]===null){resolved[id]=null;continue;}
    const value=normalizeShortcut(values[id]);
    if(value)resolved[id]=value;
  }
  // Deterministic precedence: never let corrupted storage trigger two actions.
  const seen=new Set<string>();
  for(const id of Object.keys(resolved) as ShortcutCommand[]){const value=resolved[id];if(!value)continue;const key=shortcutSignature(value,mac);if(seen.has(key))resolved[id]=null;else seen.add(key);}
  return resolved;
}
export function readShortcutPreferences(raw:string|null):ShortcutBindings {
  if(raw&&raw.length>8192)return {};
  try{const value=JSON.parse(raw??'{}');if(value?.version!==1||typeof value.bindings!=='object'||value.bindings===null)return {};const result:ShortcutBindings={};for(const id of Object.keys(shortcutDefaults) as ShortcutCommand[]){const v=value.bindings[id];if(v===null)result[id]=null;else{const n=normalizeShortcut(v);if(n)result[id]=n;}}return result;}catch{return {};}
}
export function editableShortcutPath(path:EventTarget[]):boolean {
  return path.some(node=>node instanceof Element&&(node.matches('input:not([type="checkbox"]):not([type="radio"]),textarea,select,[role="textbox"],[role="combobox"],[data-fms-shortcut-recorder]')||(node instanceof HTMLElement&&node.isContentEditable)));
}
export type ShortcutHandler = () => boolean | void;
type Registration={execute:ShortcutHandler;enabled?:()=>boolean};
const coordinators=new WeakMap<Document,Coordinator>();
class Coordinator {
  instances=new Set<ShortcutRegistry>();active:ShortcutRegistry|null=null;
  constructor(private doc:Document){doc.addEventListener('pointerdown',this.activate,true);doc.addEventListener('focusin',this.activate,true);doc.addEventListener('keydown',this.keydown);doc.addEventListener('paste',this.paste);}
  owner(event:Event):ShortcutRegistry|null {const path=event.composedPath();return [...this.instances].find(instance=>path.includes(instance.root))??(event.target===this.doc.body?(this.active??(this.instances.size===1?[...this.instances][0]!:null)):null);}
  activate=(event:Event)=>{this.active=this.owner(event);};
  keydown=(event:KeyboardEvent)=>this.owner(event)?.keydown(event);
  paste=(event:ClipboardEvent)=>this.owner(event)?.paste(event);
  remove(instance:ShortcutRegistry){this.instances.delete(instance);if(this.active===instance)this.active=null;if(this.instances.size)return;this.doc.removeEventListener('pointerdown',this.activate,true);this.doc.removeEventListener('focusin',this.activate,true);this.doc.removeEventListener('keydown',this.keydown);this.doc.removeEventListener('paste',this.paste);coordinators.delete(this.doc);}
}
export class ShortcutRegistry {
  private handlers=new Map<ShortcutCommand,Registration>();private coordinator:Coordinator;
  bindings:Record<ShortcutCommand,string|null>;enabled=true;onPaste:((event:ClipboardEvent)=>boolean)|undefined;
  constructor(readonly root:HTMLElement,readonly mac:boolean,defaults:ShortcutBindings={}){this.bindings=resolveShortcutBindings(defaults,{},mac);const doc=root.ownerDocument;this.coordinator=coordinators.get(doc)??new Coordinator(doc);coordinators.set(doc,this.coordinator);this.coordinator.instances.add(this);}
  register(id:ShortcutCommand,handler:ShortcutHandler,enabled?:()=>boolean){const registration:Registration={execute:handler,...(enabled?{enabled}:{})};this.handlers.set(id,registration);return()=>{if(this.handlers.get(id)===registration)this.handlers.delete(id);};}
  available(event:Event){return this.enabled&&!event.defaultPrevented&&!editableShortcutPath(event.composedPath())&&!this.root.querySelector('[role="dialog"],[role="alertdialog"],[role="menu"],[role="listbox"]');}
  keydown(event:KeyboardEvent){if(event.isComposing||event.repeat||event.getModifierState('AltGraph')||!this.available(event))return;const combination=eventShortcut(event);if(!combination)return;for(const id of Object.keys(this.bindings) as ShortcutCommand[]){const binding=this.bindings[id];if(!binding||shortcutSignature(binding,this.mac)!==shortcutSignature(combination,this.mac))continue;
    if(id==='copyImage'&&this.root.ownerDocument.getSelection()?.toString())return;
    // Preserve the trusted native paste event for the platform's ordinary chord.
    if(id==='pasteFiles'&&shortcutSignature(binding,this.mac)===shortcutSignature('Mod+V',this.mac))return;
    const handler=this.handlers.get(id);if(!handler||handler.enabled?.()===false)return;if(handler.execute()!==false)event.preventDefault();return;}}
  paste(event:ClipboardEvent){if(this.available(event)&&this.bindings.pasteFiles!==null&&this.onPaste?.(event))event.preventDefault();}
  destroy(){this.coordinator.remove(this);this.handlers.clear();this.onPaste=undefined;}
}
