import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
const output=await build({entryPoints:['packages/react/src/components/library/drawingGeometry.ts'],bundle:true,write:false,format:'esm'});
const {moveDrawing,resizeDrawing}=await import('data:text/javascript;base64,'+Buffer.from(output.outputFiles[0].contents).toString('base64'));

test('moving a drawing against the image edge preserves every segment',()=>{
  const mark={tool:'pen',color:'#000000',points:[[100,100],[200,150],[300,300]]};
  assert.deepEqual(moveDrawing(mark,-200,900).points,[[0,800],[100,850],[200,1000]]);
  assert.deepEqual(mark.points,[[100,100],[200,150],[300,300]]);
});

test('resizing scales all vertices around the opposite corner without mutating the source',()=>{
  const mark={tool:'pen',color:'#000000',points:[[100,100],[200,200],[300,300]]};
  assert.deepEqual(resizeDrawing(mark,2,[500,500]).points,[[100,100],[300,300],[500,500]]);
  assert.deepEqual(resizeDrawing(mark,0,[0,0]).points,[[0,0],[150,150],[300,300]]);
  assert.deepEqual(mark.points,[[100,100],[200,200],[300,300]]);
});
