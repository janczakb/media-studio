import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {createHash} from 'node:crypto';
const root=new URL('../',import.meta.url);
const metadata=async name=>{
 const value=JSON.parse(await readFile(new URL(`packages/react/dist/${name}`,root),'utf8'));
 return {inputs:Object.fromEntries(Object.keys(value.inputs).map(name=>[name,{}])),outputs:Object.fromEntries(Object.entries(value.outputs).map(([name,value])=>[name,{imports:value.imports}]))};
};
const hashes={};for(const name of ['studio.js','workspace-emoji.js'])hashes[name]=createHash('sha256').update(await readFile(new URL(`packages/react/dist/${name}`,root))).digest('hex');
const directory=new URL('tests/fixtures/build/',root);await mkdir(directory,{recursive:true});
await writeFile(new URL('asset-audit.json',directory),JSON.stringify({hashes,main:await metadata('build-meta.json'),picker:await metadata('emoji-build-meta.json')},null,2)+'\n');
