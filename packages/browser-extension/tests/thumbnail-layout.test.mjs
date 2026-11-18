import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFile} from 'node:fs/promises';
import {transform} from 'esbuild';
const {code}=await transform(await readFile(new URL('../src/core/thumbnail-layout.ts',import.meta.url),'utf8'),{loader:'ts',format:'esm'});
const {thumbnailBounds}=await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'));
test('portrait, landscape and square previews preserve natural aspect inside the cell',()=>{
 for(const [w,h] of [[2000,3000],[3000,2000],[2000,2000],[300,3000]]){
  const bounds=thumbnailBounds(w,h,180,240);
  assert.ok(bounds.width<=180&&bounds.height<=240);
  assert.ok(Math.abs(bounds.width/bounds.height-w/h)<1e-8);
 }
 assert.deepEqual(thumbnailBounds(2000,3000,180,240),{width:160,height:240});
 assert.deepEqual(thumbnailBounds(40,30,180,240),{width:180,height:135});
 assert.deepEqual(thumbnailBounds(20,30,180,240),{width:160,height:240});
});
test('missing dimensions keep a bounded placeholder until the actual image loads',()=>{
 assert.deepEqual(thumbnailBounds(0,0,180,240),{width:180,height:240});
});
