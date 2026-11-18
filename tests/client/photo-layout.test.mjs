import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
const output=await build({stdin:{contents:"export * from './packages/react/src/components/photos/photo-zoom'; export * from './packages/react/src/components/photos/timeline';",resolveDir:process.cwd(),loader:'ts'},bundle:true,write:false,format:'esm'});
const {photoGridGeometry,photoGeometryForColumns,photoPinchLevel,photoWheelDelta,clampPhotoZoom,timelineRows}=await import('data:text/javascript;base64,'+Buffer.from(output.outputFiles[0].contents).toString('base64'));

test('photo geometry fills its available row at every supported zoom without negative or overflowing cells',()=>{
  for(const width of [1,64,320,342,700,1144,2560])for(const zoom of [64,90,180,250,480]){
    const {columns,cell,row}=photoGridGeometry(width,zoom);
    assert.ok(columns>=1&&Number.isInteger(columns));assert.ok(cell>0);
    assert.ok(Math.abs(columns*cell+(columns-1)*4-width)<0.001);
    assert.equal(row,cell+4);
  }
  assert.equal(clampPhotoZoom(Infinity),64);
  assert.equal(clampPhotoZoom(-1),64);
  assert.equal(clampPhotoZoom(9999),480);
  assert.ok(photoWheelDelta(-10,0)>0);
  assert.ok(photoWheelDelta(10,0)<0);
  assert.equal(photoWheelDelta(-1,1),photoWheelDelta(-18,0));
  assert.equal(photoWheelDelta(-999,2),.4);
  assert.equal(photoWheelDelta(NaN,0),0);
});

test('timeline rows retain every photo once across period boundaries and use explicit UTC dates',()=>{
  const entries=[
    {id:'a',createdAt:'2026-01-01T00:00:00Z',updatedAt:'2026-01-01T00:00:00Z'},
    {id:'b',createdAt:'2025-12-31T23:59:59Z',updatedAt:'2025-12-31T23:59:59Z'},
    {id:'c',createdAt:'2025-12-31T12:00:00Z',updatedAt:'2025-12-31T12:00:00Z'},
  ];
  for(const period of ['years','months','days']){
    const rows=timelineRows(entries,2,180,period);
    assert.deepEqual(rows.flatMap(row=>row.entries).map(item=>item.id),['a','b','c']);
    const headings=rows.filter(row=>row.label!==undefined);
    assert.equal(headings.length,2);
    assert.equal(headings[0].key,period==='years'?'group-2026':period==='months'?'group-2026-01':'group-2026-01-01');
    assert.ok(rows.every((row,index)=>index===0?row.start===0:row.start===rows[index-1].start+rows[index-1].height));
  }
  assert.equal(timelineRows([],2,180,'days').length,0);
});


test('pinch thresholds come from fitted geometry, with direction-sensitive release and bounded edge resistance',()=>{
  const width=1144,columns=6;
  const current=photoGeometryForColumns(width,columns).cell;
  const inward=Math.log(photoGeometryForColumns(width,5).cell/current);
  const outward=Math.log(photoGeometryForColumns(width,7).cell/current);
  assert.equal(photoPinchLevel(width,columns,inward*.9).columns,6);
  assert.equal(photoPinchLevel(width,columns,inward*1.01).columns,5);
  assert.equal(photoPinchLevel(width,columns,outward*.9).columns,6);
  assert.equal(photoPinchLevel(width,columns,outward*1.01).columns,7);
  assert.equal(photoPinchLevel(width,columns,inward*.2,true).columns,6);
  assert.equal(photoPinchLevel(width,columns,inward*.5,true).columns,5);
  assert.equal(photoPinchLevel(width,columns,outward*.5,true).columns,7);
  const largest=photoGridGeometry(width,480).columns;
  const limit=photoPinchLevel(width,largest,100);
  assert.equal(limit.columns,largest);
  assert.ok(limit.scale>1&&limit.scale<1.18);
  const smallest=photoGridGeometry(width,64).columns;
  const lower=photoPinchLevel(width,smallest,-100);
  assert.equal(lower.columns,smallest);
  assert.ok(lower.scale<1&&lower.scale>.85);
});
