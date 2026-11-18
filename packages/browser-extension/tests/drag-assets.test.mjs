import assert from 'node:assert/strict';
import {test} from 'node:test';
import {build} from 'esbuild';
import {fileURLToPath} from 'node:url';
const result=await build({entryPoints:[fileURLToPath(new URL('../src/content/drag-assets.ts',import.meta.url))],bundle:true,format:'esm',platform:'node',write:false});
const {assetsFromUriList}=await import('data:text/javascript;base64,'+Buffer.from(result.outputFiles[0].text).toString('base64'));
const primary={id:'one',url:'https://source.test/a.jpg',previewUrl:'https://source.test/a-small.jpg',name:'Original.jpg',kind:'image',pageUrl:'https://source.test/gallery',width:200,height:100,description:''};
test('multiple dragged URLs retain identity, metadata and source scope',()=>{
 const assets=assetsFromUriList('# comment\r\nhttps://source.test/a.jpg\r\nhttps://source.test/b.png\nhttps://source.test/b.png\nhttps://source.test/report.pdf\nfile:///private/secret',primary);
 assert.equal(assets.length,3);assert.equal(assets[0],primary);assert.equal(assets[1].kind,'image');assert.equal(assets[2].kind,'document');assert.ok(assets.every(item=>item.pageUrl===primary.pageUrl));
});
test('empty native payload preserves the extracted single file and batches are bounded',()=>{
 assert.deepEqual(assetsFromUriList('',primary),[primary]);
 assert.throws(()=>assetsFromUriList(Array.from({length:301},(_,i)=>`https://source.test/${i}.jpg`).join('\n'),primary));
});
