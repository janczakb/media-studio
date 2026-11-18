import {test} from 'node:test';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {performance} from 'node:perf_hooks';
const output=await build({entryPoints:[fileURLToPath(new URL('../../packages/react/src/folders/FolderTreeClient.ts',import.meta.url))],bundle:true,write:false,format:'esm',platform:'node'});
const {FolderTreeClient,createFolderTreeTransport}=await import('data:text/javascript;base64,'+Buffer.from(output.outputFiles[0].text).toString('base64'));
const folder=(id,hasChildFolders=false)=>({id,name:id,kind:'folder',hasChildFolders,revision:1});
const page=(items,next=null,previous=null)=>({items,nextCursor:next,previousCursor:previous,hasMore:next!==null,canWrite:true});
const settle=async tree=>{while(tree.getStats().requests)await new Promise(resolve=>setImmediate(resolve));};
const load=async(tree,rows)=>{tree.loadVisible(rows);await settle(tree);};

test('folder pages are lazy, requests deduplicate and collapsed late responses cannot reopen the branch',async()=>{
  let release,calls=0,signal;
  const tree=new FolderTreeClient('one',async(_workspace,query,_cursor,inputSignal)=>{calls++;signal=inputSignal;return new Promise(resolve=>{release=()=>resolve(page([folder('child')]));});});
  assert.equal(calls,0);
  tree.toggle(null);
  tree.loadVisible(tree.getSnapshot().rows);tree.loadVisible(tree.getSnapshot().rows);
  assert.equal(calls,1);
  tree.toggle(null);assert.equal(signal.aborted,true);
  release();await settle(tree);
  assert.deepEqual(tree.getSnapshot().rows,[]);
  assert.equal(tree.getSnapshot().expanded.has(null),false);
  tree.destroy();
});

test('cursor windows retain bounded payloads and metadata when traversing a million-folder source in both directions',async t=>{
  let calls=0;
  const tree=new FolderTreeClient('one',async(_workspace,query,cursor)=>{
    calls++;const start=Number(cursor??0);
    return page(Array.from({length:query.limit},(_,i)=>folder('folder-'+(start+i))),start+query.limit<1000000?String(start+query.limit):null,start?String(start-query.limit):null);
  });
  const start=performance.now();tree.toggle(null);await load(tree,tree.getSnapshot().rows);
  for(let index=0;index<300;index++){
    const next=tree.getSnapshot().rows.at(-1);assert.equal(next.edge,'next');
    await load(tree,[next]);
    assert.ok(tree.getStats().pages<=4);assert.ok(tree.getStats().folders<=200);
  }
  const before=Number(tree.getSnapshot().rows.find(row=>row.folder).folder.id.split('-')[1]);
  const previous=tree.getSnapshot().rows[0];assert.equal(previous.edge,'previous');await load(tree,[previous]);
  const after=Number(tree.getSnapshot().rows.find(row=>row.folder).folder.id.split('-')[1]);assert.ok(after<before);
  t.diagnostic(`Synthetic on-demand source of 1,000,000 folders. Traversed 300 forward windows and one backward window in ${(performance.now()-start).toFixed(1)} ms. ${calls} in-memory requests, ${tree.getStats().folders} retained summaries, ${tree.getStats().pages} cursor descriptors. No network/database timing.`);
  tree.destroy();
});

test('deep path is revealed without scanning sibling pages and cached reopening performs no transfer',async()=>{
  const calls=[];
  const tree=new FolderTreeClient('one',async(_workspace,query)=>{calls.push(query.parent);return page(query.parent===null?[folder('other')]:[]);});
  tree.reveal([{id:'ancestor',name:'Ancestor',hasChildFolders:true},{id:'selected',name:'Selected',hasChildFolders:false}]);
  assert.deepEqual(tree.getSnapshot().rows.filter(row=>row.folder).map(row=>row.folder.id),['ancestor','selected']);
  assert.deepEqual(calls,[]);
  assert.deepEqual(tree.pathTo({id:'selected',name:'Selected'}).map(folder=>folder.id),['ancestor','selected']);
  await load(tree,tree.getSnapshot().rows);
  assert.equal(calls.length,2);
  tree.toggle(null);tree.toggle(null);await load(tree,tree.getSnapshot().rows);
  assert.equal(calls.length,2);
  tree.destroy();
});

test('successful folder writes invalidate their parents and workspace cache payloads stay isolated',async()=>{
  let created=false;
  const registry=createFolderTreeTransport({
    async browse(workspace){return page([folder(workspace+'-folder'),...(created&&workspace==='one'?[folder('created')]:[])]);},
    async createFolder(){created=true;return folder('created');},
  });
  const one=registry.tree('one'),two=registry.tree('two');one.toggle(null);two.toggle(null);
  await load(one,one.getSnapshot().rows);await load(two,two.getSnapshot().rows);
  assert.equal(one.getSnapshot().rows[0].folder.id,'one-folder');assert.equal(two.getSnapshot().rows[0].folder.id,'two-folder');
  await registry.transport.createFolder('one',null,'Created',new AbortController().signal);
  assert.equal(one.getSnapshot().rows[0].folder.id,'one-folder');assert.equal(two.getSnapshot().rows[0].folder.id,'two-folder');
  await load(one,one.getSnapshot().rows);
  assert.ok(one.getSnapshot().rows.some(row=>row.folder?.id==='created'));
  registry.destroy();
});

test('custom transport instances preserve their receiver and method identity',async()=>{
  class Transport {
    #name='Custom library';
    async browse(){return page([folder(this.#name)]);}
    async createFolder(){return folder(this.#name);}
  }
  const source=new Transport(),registry=createFolderTreeTransport(source);
  assert.equal(registry.transport.browse,registry.transport.browse);
  assert.equal((await registry.transport.browse()).items[0].id,'Custom library');
  assert.equal((await registry.transport.createFolder('one',null,'Name',new AbortController().signal)).id,'Custom library');
  registry.destroy();
});

test('deep contexts hoist their current folder without expanding or truncating the ancestor chain',async()=>{
  const calls=[];
  const tree=new FolderTreeClient('one',async(_workspace,query)=>{calls.push(query.parent);return page([folder('child')]);});
  tree.reveal(Array.from({length:10000},(_,index)=>({id:'level-'+index,name:'Level '+index,hasChildFolders:true})));
  assert.equal(tree.getSnapshot().root.id,'level-9999');
  assert.equal(tree.getSnapshot().rows.filter(row=>row.folder).length,0);
  await load(tree,tree.getSnapshot().rows);
  assert.deepEqual(calls,['level-9999']);
  assert.equal(tree.getSnapshot().rows[0].folder.id,'child');
  assert.ok(tree.pathTo(folder('child')).length<=8);
  tree.reveal([]);
  assert.equal(tree.getSnapshot().root,null);
  tree.destroy();
});

test('expanded ancestors stay visible when deep branches exceed the payload cache',async()=>{
  const tree=new FolderTreeClient('one',async(_workspace,query)=>{
    const index=query.parent===null?0:Number(query.parent.slice(6))+1;
    return page([folder('level-'+index,true)]);
  });
  tree.toggle(null);
  for(let depth=0;depth<30;depth++){
    const rows=tree.getSnapshot().rows;
    const pending=rows.findLast(row=>!row.folder);
    await load(tree,[pending]);
    const row=tree.getSnapshot().rows.find(row=>row.folder?.id==='level-'+depth);
    assert.ok(row);
    tree.toggle(row.folder.id,row.folder,row.parent);
  }
  assert.equal(tree.getSnapshot().rows.filter(row=>row.folder).length,30);
  assert.ok(tree.getStats().idleCachedPages<=12);assert.ok(tree.getStats().cachedPages<=64*4+12);
  tree.destroy();
});


test('restored descendants below a contextual root retain parent links and drain visible loaders',async()=>{
  const calls=[];
  const tree=new FolderTreeClient('one',async(_,query)=>{
    calls.push(query.parent);
    const index=Number(query.parent.slice(1));
    return page(index<15?[folder('n'+(index+1),index<14)]:[]);
  });
  tree.restore(Array.from({length:6},(_,i)=>'n'+(i+10)));
  tree.reveal([{id:'n9',name:'Level 9'},{id:'n10',name:'Level 10',hasChildFolders:true}],true);
  for(let i=0;i<8;i++)await load(tree,tree.getSnapshot().rows);
  assert.deepEqual(calls,['n10','n11','n12','n13','n14','n15']);
  assert.ok(tree.getSnapshot().rows.every(row=>row.folder));
  assert.deepEqual(tree.pathTo({id:'n14',name:'Level 14'}).map(item=>item.id),['n9','n10','n11','n12','n13','n14']);
  assert.equal(tree.getStats().requests,0);
  tree.reveal([{id:'n10',name:'Level 10'},{id:'n11',name:'Level 11',hasChildFolders:true}],true);
  await load(tree,tree.getSnapshot().rows);
  assert.equal(tree.getSnapshot().root.id,'n11');
  assert.ok(tree.getSnapshot().rows.every(row=>row.folder));
  tree.destroy();
});


test('refresh preserves expanded folder identities beyond the idle response-cache budget',async()=>{
 const tree=new FolderTreeClient('one',async(_workspace,query)=>{const index=query.parent===null?-1:Number(query.parent.slice(1));return page(index<15?[folder('n'+(index+1),true)]:[]);},{cachedPages:4});
 tree.restore([null,...Array.from({length:16},(_,i)=>'n'+i)]);
 for(let i=0;i<18;i++)await load(tree,tree.getSnapshot().rows);
 const before=tree.getSnapshot().rows.filter(row=>row.folder).map(row=>row.folder.id);
 assert.ok(before.length>8);assert.ok(tree.getStats().idleCachedPages<=4);assert.ok(tree.getStats().cachedPages<=64*4+4);
 tree.refresh();
 assert.deepEqual(tree.getSnapshot().rows.filter(row=>row.folder).map(row=>row.folder.id),before);
 tree.destroy();
});

test('many small expanded branches do not evict sibling names or refetch their summaries',async()=>{
 const calls=[];
 const tree=new FolderTreeClient('one',async(_,query)=>{
  calls.push(query.parent);
  if(query.parent===null)return page([folder('deep',true),folder('performance',true),folder('last')]);
  if(query.parent==='performance')return page(Array.from({length:50},(_,i)=>folder('sibling-'+i)));
  const index=query.parent==='deep'?0:Number(query.parent.slice(1))+1;
  return page(index<28?[folder('n'+index,true)]:[]);
 });
 tree.toggle(null);await load(tree,tree.getSnapshot().rows);
 tree.toggle('performance',folder('performance',true));await load(tree,tree.getSnapshot().rows);
 tree.toggle('deep',folder('deep',true));
 for(let i=0;i<29;i++){
  await load(tree,tree.getSnapshot().rows);
  const child=tree.getSnapshot().rows.find(row=>row.folder?.id==='n'+i);
  if(child)tree.toggle(child.folder.id,child.folder,child.parent);
 }
 const siblings=tree.getSnapshot().rows.filter(row=>row.parent==='performance');
 assert.equal(siblings.filter(row=>row.folder).length,50);
 const reads=calls.length;
 await load(tree,siblings);
 assert.equal(calls.length,reads,'cached names remain usable after payload eviction');
 assert.ok(tree.getStats().idleCachedPages<=12);assert.ok(tree.getStats().cachedPages<=64*4+12);
 assert.ok(tree.getStats().summaries<=600);
 tree.destroy();
});

test('cold restoration prepares bounded expanded branches before backward navigation',async()=>{
 const calls=[];
 const tree=new FolderTreeClient('one',async(_,query,cursor)=>{
  calls.push({parent:query.parent,cursor,limit:query.limit});
  if(query.parent===null)return page([folder('chain',true),folder('selected',true)]);
  if(query.parent==='selected')return page(Array.from({length:50},(_,i)=>folder('child-'+i)),'next');
  const index=query.parent==='chain'?0:Number(query.parent.slice(1))+1;
  return page(index<30?[folder('n'+index,index<29)]:[]);
 });
 tree.restore([null,'chain',...Array.from({length:29},(_,i)=>'n'+i),'selected']);
 tree.reveal([{id:'selected',name:'selected'}]);
 for(let pass=0;pass<40&&tree.getSnapshot().restoring;pass++)await load(tree,tree.getSnapshot().rows.filter(row=>row.folder?.id==='selected'));
 assert.equal(tree.getSnapshot().restoring,false);
 assert.equal(tree.getSnapshot().rows.filter(row=>row.parent!=='selected'&&!row.edge&&!row.folder).length,0,'no unknown expanded prefix remains above the selected folder');
 assert.ok(calls.length<=33);assert.ok(calls.every(call=>call.cursor===null&&call.limit===50));
 const before=calls.length;
 await load(tree,tree.getSnapshot().rows.slice(0,25));
 assert.equal(calls.length,before,'scrolling back into restored branches performs no reconstruction reads');
 assert.ok(tree.getStats().branches<=96);
 tree.destroy();
});
