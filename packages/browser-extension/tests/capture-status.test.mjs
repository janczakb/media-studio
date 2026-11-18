import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFile} from 'node:fs/promises';
import {transform} from 'esbuild';
const {code}=await transform(await readFile(new URL('../src/core/capture-status.ts',import.meta.url),'utf8'),{loader:'ts',format:'esm'});
const {captureStatus}=await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'));
const job={connectionId:'workspace-a',asset:{pageUrl:'https://source.test/photos'},state:'uploading',entry:'partial-entry'};
test('a queued or uploading job never confirms a completed save',()=>{
 for(const state of ['queued','downloading','uploading'])assert.deepEqual(captureStatus({...job,state},'workspace-a','https://source.test/photos'),{state,entry:null});
 assert.deepEqual(captureStatus({...job,state:'done'},'workspace-a','https://source.test/photos'),{state:'done',entry:'partial-entry'});
 assert.deepEqual(captureStatus({...job,state:'done',entry:null},'workspace-a','https://source.test/photos'),{state:'error',entry:null});
});
test('job status is isolated to the selected connection and source origin',()=>{
 assert.throws(()=>captureStatus(job,'workspace-b','https://source.test/photos'));
 assert.throws(()=>captureStatus(job,'workspace-a','https://another.test/photos'));
 assert.throws(()=>captureStatus(undefined,'workspace-a','https://source.test/photos'));
});
const {summarizeCaptures}=await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'));
test('a batch reports success only when every file is persisted',()=>{
 assert.deepEqual(summarizeCaptures([{state:'done',entry:'a'},{state:'uploading',entry:null}]),{total:2,done:1,failed:0,pending:1});
 assert.deepEqual(summarizeCaptures([{state:'done',entry:'a'},{state:'error',entry:null},{state:'done',entry:null}]),{total:3,done:1,failed:2,pending:0});
});
