import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
const bundled=await build({entryPoints:['packages/react/src/shortcuts/registry.ts','packages/react/src/shortcuts/clipboard.ts'],bundle:true,write:false,outdir:'out',format:'esm'});
const modules=await Promise.all(bundled.outputFiles.map(file=>import('data:text/javascript;base64,'+Buffer.from(file.contents).toString('base64'))));
const registry=modules.find(value=>value.ShortcutRegistry),clipboard=modules.find(value=>value.clipboardFiles);
const {normalizeShortcut,resolveShortcutBindings,shortcutSignature,eventShortcut,reservedShortcut,readShortcutPreferences,ShortcutRegistry}=registry;
test('binding normalization uses platform modifiers, physical keys and rejects malformed chords',()=>{
 assert.equal(normalizeShortcut('command + shift + k'),'Meta+Shift+K');
 assert.equal(normalizeShortcut('Mod+Ctrl+K'),'Mod+Ctrl+K');assert.equal(normalizeShortcut('Mod+Mod+K'),null);assert.equal(normalizeShortcut('Ctrl++K'),null);
 assert.equal(normalizeShortcut('Ctrl+Alt+'),null);assert.equal(normalizeShortcut('Control+Escape'),'Ctrl+Escape');
 assert.equal(shortcutSignature('Mod+K',true),shortcutSignature('Meta+K',true));
 assert.equal(shortcutSignature('Mod+K',false),shortcutSignature('Ctrl+K',false));
 assert.equal(eventShortcut({key:'К',code:'KeyK',ctrlKey:true,metaKey:false,altKey:false,shiftKey:false}),'Ctrl+K');
 assert.equal(normalizeShortcut('mod+,'),'Mod+Comma');assert.equal(normalizeShortcut('alt+up'),'Alt+ArrowUp');assert.equal(normalizeShortcut('f12'),'F12');
});
test('reserved browser actions are detected and collisions dispatch at most one command',()=>{
 assert.ok(reservedShortcut('Mod+L',true));assert.ok(reservedShortcut('Mod+W',false));assert.ok(reservedShortcut('Mod+Shift+N',true));
 assert.equal(resolveShortcutBindings({search:'Mod+L'},{},false).search,'Mod+L');
 const values=resolveShortcutBindings({},{upload:'Mod+K',selectAll:null},false);
 assert.equal(values.search,'Mod+K');assert.equal(values.upload,null);assert.equal(values.selectAll,null);
 assert.equal(resolveShortcutBindings({search:'Alt+K'},{search:'bad+binding'},false).search,'Alt+K');
});
test('persisted bindings are versioned, bounded to known commands and recover safely from corrupt storage',()=>{
 assert.deepEqual(readShortcutPreferences('{broken'),{});assert.deepEqual(readShortcutPreferences('{"version":2,"bindings":{"search":"Mod+J"}}'),{});
 assert.deepEqual(readShortcutPreferences('{"version":1,"bindings":{"search":"Mod+J","pasteFiles":null,"evil":"Mod+X"}}'),{search:'Mod+J',pasteFiles:null});
});
test('clipboard input preserves file identities, names generated images and admits batches atomically',()=>{
 const named=new File(['content'],'notes.pdf',{type:'application/pdf'}),image=new File(['png'],'image.png',{type:'image/png'});
 const limits=clipboard.clipboardLimits({maxFiles:2,maxBytes:20});
 const files=clipboard.clipboardFiles({files:[named,image],items:[]},limits);
 assert.equal(files[0],named);assert.match(files[1].name,/^clipboard-.*\.png$/);assert.equal(files[1].type,'image/png');
 assert.throws(()=>clipboard.clipboardFiles({files:[named,image],items:[]},{...limits,maxFiles:1}),/clipboardLimit/);
 assert.throws(()=>clipboard.clipboardFiles({files:[named,image],items:[]},{...limits,maxBytes:2}),/clipboardLimit/);
 assert.throws(()=>clipboard.clipboardFiles({files:[new File([],'empty.png')],items:[]},limits),/clipboardLimit/);
 assert.deepEqual(clipboard.clipboardFiles({files:[],items:[{kind:'string'}]},limits),[]);
});
test('clipboard limits clamp invalid application configuration to finite resource budgets',()=>{
 const limits=clipboard.clipboardLimits({maxFiles:Infinity,maxBytes:-1,maxPixels:999999999});
 assert.equal(limits.maxFiles,20);assert.equal(limits.maxBytes,1);assert.equal(limits.maxPixels,40000000);
});
class TestElement {constructor(selector=''){this.selector=selector;}matches(selector){return this.selector==='input'&&selector.includes('input');}querySelector(){return null;}}
class TestHTMLElement extends TestElement {isContentEditable=false;}
function key(doc,root,key='k',code='KeyK',extra={}){const e=new Event('keydown',{cancelable:true});Object.assign(e,{key,code,ctrlKey:true,metaKey:false,altKey:false,shiftKey:false,repeat:false,isComposing:false,getModifierState:()=>false,composedPath:()=>[root,doc],...extra});doc.dispatchEvent(e);return e;}
test('one coordinator isolates instances, preserves editors/dialogs, handles native paste once and disposes handlers',()=>{
 const oldElement=globalThis.Element,oldHTMLElement=globalThis.HTMLElement;globalThis.Element=TestElement;globalThis.HTMLElement=TestHTMLElement;
 try{
  const doc=new EventTarget();doc.body={};const a=new TestHTMLElement(),b=new TestHTMLElement();a.ownerDocument=b.ownerDocument=doc;
  const first=new ShortcutRegistry(a,false),second=new ShortcutRegistry(b,false);let callsA=0,callsB=0,pastes=0;
  first.register('search',()=>callsA++);second.register('search',()=>callsB++);
  assert.ok(key(doc,a).defaultPrevented);assert.equal(callsA,1);assert.equal(callsB,0);
  key(doc,b);assert.equal(callsB,1);
  key(doc,a,'k','KeyK',{composedPath:()=>[new TestHTMLElement('input'),a,doc]});assert.equal(callsA,1);
  a.querySelector=()=>({});key(doc,a);assert.equal(callsA,1);a.querySelector=()=>null;
  key(doc,a,'k','KeyK',{repeat:true});key(doc,a,'k','KeyK',{isComposing:true});assert.equal(callsA,1);
  first.onPaste=()=>{pastes++;return true;};const nativeKey=key(doc,a,'v','KeyV');assert.equal(nativeKey.defaultPrevented,false);
  const paste=new Event('paste',{cancelable:true});Object.assign(paste,{composedPath:()=>[a,doc]});doc.dispatchEvent(paste);assert.equal(pastes,1);assert.ok(paste.defaultPrevented);
  first.enabled=false;key(doc,a);assert.equal(callsA,1);first.destroy();second.destroy();key(doc,b);assert.equal(callsB,1);
 }finally{globalThis.Element=oldElement;globalThis.HTMLElement=oldHTMLElement;}
});
