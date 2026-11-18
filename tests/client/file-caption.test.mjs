import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
const output = await build({entryPoints:['packages/react/src/file-caption.ts'],bundle:true,write:false,format:'esm'});
const {formatFileBytes,fileCaption} = await import('data:text/javascript;base64,'+Buffer.from(output.outputFiles[0].contents).toString('base64'));
test('file captions use type, real dimensions and compact rounded decimal sizes',()=>{
 assert.equal(fileCaption({name:'icon.svg',mimeType:'image/svg+xml',width:128,height:128,size:755},'en'),'SVG · 128 × 128 · 755 B');
 assert.equal(fileCaption({name:'photo.jpg',mimeType:'image/jpeg',width:1920,height:1080,size:6563738},'en'),'JPG · 1920 × 1080 · 6.6 MB');
 assert.equal(fileCaption({name:'report.pdf',mimeType:'application/pdf',width:null,height:null,size:1500},'en'),'PDF · 1.5 KB');
 assert.equal(formatFileBytes(999999,'en'),'1 MB');
 assert.equal(formatFileBytes(0,'en'),'0 B');
 assert.equal(formatFileBytes(null,'en'),'—');
 assert.equal(formatFileBytes(NaN,'en'),'—');
 assert.equal(formatFileBytes(-10,'en'),'—');
});
