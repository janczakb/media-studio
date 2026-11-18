import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile,readdir} from 'node:fs/promises';
import {createHash} from 'node:crypto';

const dist=new URL('../../packages/react/dist/',import.meta.url);
test('the main UI and emoji engine do not embed translation catalogs or emoji datasets',async()=>{
 const audit=JSON.parse(await readFile(new URL('../fixtures/build/asset-audit.json',import.meta.url),'utf8'));
 for(const [name,hash]of Object.entries(audit.hashes))assert.equal(createHash('sha256').update(await readFile(new URL(name,dist))).digest('hex'),hash,'Build audit must match the tested bundle');
 const main=audit.main;
 assert.deepEqual(Object.keys(main.inputs).filter(path=>path.includes('/locales/')),[]);
 const picker=audit.picker;
 assert.deepEqual(Object.keys(picker.inputs).filter(path=>path.includes('/data/emojis-')),[]);
 const lazyEnglish=Object.values(picker.outputs).flatMap(output=>output.imports).filter(item=>item.path==='./emoji-data-en.js');
 assert.equal(lazyEnglish.length,1);assert.equal(lazyEnglish[0].kind,'dynamic-import');
 const names=await readdir(dist);assert.equal(names.filter(name=>/^emoji-data-[a-z-]+\.js$/.test(name)).length,28);
 assert.ok(names.includes('studio-locale-en.js'));assert.ok(names.includes('studio-locale-pl.js'));
});
