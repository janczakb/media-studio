import {readdir,readFile} from 'node:fs/promises';
/** Match the production split-asset contract in fixture servers. */
export async function localeAssets({raw=false}={}){
 const base=new URL('../../packages/react/dist/',import.meta.url);
 const names=(await readdir(base)).filter(name=>/^(studio-locale|emoji-data)-[a-z-]+\.js$/.test(name));
 return Promise.all(names.map(async name=>{const body=await readFile(new URL(name,base));return [raw?name:'/'+name,raw?body:{type:'text/javascript',body}];}));
}
