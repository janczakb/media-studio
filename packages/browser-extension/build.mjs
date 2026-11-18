import {build} from 'esbuild';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {mkdir,copyFile,writeFile,readFile,stat} from 'node:fs/promises';
import {resolve,dirname} from 'node:path';
import {zipSync} from 'fflate';
import {createHash} from 'node:crypto';
import {fileURLToPath} from 'node:url';
const root=dirname(fileURLToPath(import.meta.url));process.chdir(root);
const out=resolve('dist');await mkdir(out,{recursive:true});
const common={absWorkingDir:root,bundle:true,minify:true,target:'es2022',jsx:'automatic',define:{'process.env.NODE_ENV':'"production"'},legalComments:'none'};
for(const [name,entry]of Object.entries({background:'src/background/index.ts',content:'src/content/index.ts','drag-ui':'src/content/drag-ui.tsx',ui:'src/ui/index.tsx'}))await build({...common,entryPoints:[entry],outfile:`dist/${name}.js`,format:'iife'});
const tailwind = dirname(createRequire(import.meta.url).resolve('@tailwindcss/cli/package.json'));
execFileSync(process.execPath,[resolve(tailwind, 'dist/index.mjs'),'-i','src/ui/styles.css','-o','dist/ui.css','--minify'],{stdio:'inherit'});
await copyFile('src/content/content.css','dist/content.css');
await copyFile('src/assets/capture-folder-outline.svg','dist/capture-folder-outline.svg');
const manifest={manifest_version:3,name:'FLEX Capture',version:'0.1.0',description:'Collect website files and screenshots in your FLEX Media Studio workspace.',permissions:['activeTab','contextMenus','scripting','storage','alarms'],optional_host_permissions:['http://*/*','https://*/*'],action:{default_popup:'popup.html',default_title:'FLEX Capture'},options_ui:{page:'popup.html?settings=1',open_in_tab:true},web_accessible_resources:[{resources:['ui.css','content.css','capture-folder-outline.svg'],matches:['http://*/*','https://*/*']}],commands:{'collect-page':{suggested_key:{default:'Alt+Shift+1'},description:'Collect website files'},'capture-area':{suggested_key:{default:'Alt+Shift+2'},description:'Capture an area'},'capture-visible':{suggested_key:{default:'Alt+Shift+3'},description:'Capture visible page'}},content_security_policy:{extension_pages:"script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"}};
for(const browser of ['chrome','firefox']){
 const dir=resolve('dist',browser);await mkdir(dir,{recursive:true});
 const m={...manifest,background:browser==='chrome'?{service_worker:'background.js'}:{scripts:['background.js'],persistent:false},...(browser==='firefox'?{browser_specific_settings:{gecko:{id:'capture@useflex.local',strict_min_version:'140.0',data_collection_permissions:{required:['browsingActivity','websiteContent']}}}}:{minimum_chrome_version:'120'})};
 await writeFile(`${dir}/manifest.json`,JSON.stringify(m,null,2));
 for(const f of ['background.js','content.js','drag-ui.js','ui.js','ui.css','content.css','capture-folder-outline.svg'])await copyFile(`dist/${f}`,`${dir}/${f}`);
 for(const page of ['popup','manager'])await writeFile(`${dir}/${page}.html`,`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FLEX Capture</title><link rel="stylesheet" href="ui.css"></head><body${page === 'popup' ? ' class="fc-popup-page"' : ''}><div id="app"></div><script src="ui.js" defer></script></body></html>`);
}
// Publish an explicit file list: stale local files and desktop metadata must
// never enter downloadable extensions. Compression is bounded by these entries.
const releaseFiles=['manifest.json','background.js','content.js','drag-ui.js','ui.js','ui.css','content.css','capture-folder-outline.svg','popup.html','manager.html'];
const checksums=[];
for(const browser of ['chrome','firefox']){
 const contents={};
 for(const name of releaseFiles)contents[name]=new Uint8Array(await readFile(resolve('dist',browser,name)));
 contents['LICENSE']=new Uint8Array(await readFile(resolve('../../LICENSE')));
 contents['INSTALL.md']=new TextEncoder().encode(browser==='chrome'
  ? 'Extract this ZIP. In chrome://extensions (or edge://extensions), enable Developer mode and choose Load unpacked. Select the extracted folder containing manifest.json. Connect your own Studio installation from the popup. This is an unpacked release, not a store-installed extension.\n'
  : 'Extract this ZIP. In about:debugging#/runtime/this-firefox choose Load Temporary Add-on and select manifest.json. Firefox temporary installations are removed when Firefox restarts. This ZIP is not a signed permanent Firefox add-on. Connect your own Studio installation from the popup.\n');
 const archive=zipSync(contents,{level:6,mtime:new Date(1980,0,1,0,0,0)});
 const name=`flex-capture-${browser}.zip`;
 await writeFile(resolve('dist',name),archive);
 checksums.push(`${createHash('sha256').update(archive).digest('hex')}  ${name}`);
}
await writeFile(resolve('dist','SHA256SUMS.txt'),checksums.join('\n')+'\n');

const sizes={};for(const f of ['background.js','content.js','drag-ui.js','ui.js','ui.css'])sizes[f]=(await stat(`dist/${f}`)).size;await writeFile('dist/sizes.json',JSON.stringify(sizes,null,2));console.log(sizes);
