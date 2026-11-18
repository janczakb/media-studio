import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../packages/laravel/resources/js/push-worker.js', import.meta.url), 'utf8');
function worker(windows = []) {
  const events = new Map();
  const shown = [];
  const opened = [];
  const self = {
    location: {origin: 'https://studio.example.test'},
    addEventListener: (type, handler) => events.set(type, handler),
    registration: {showNotification: async (...args) => shown.push(args)},
    clients: {matchAll: async () => windows, openWindow: async url => opened.push(url)},
  };
  vm.runInNewContext(source, {self, URL});
  async function event(type, data) {
    const promises = [];
    events.get(type)({...data, waitUntil: promise => promises.push(promise)});
    await Promise.all(promises);
  }
  return {event, shown, opened};
}
test('a push creates a system notification and normalizes its link to the current origin', async () => {
  const current = worker();
  const workspace = '01M6TF7X1MD67JKPT1BRPZSAJ6';
  await current.event('push', {data: {json: () => ({body: 'A new update', workspace, path: '/studio'})}});
  assert.equal(current.shown[0][0], 'FLEX Media Studio');
  const options = current.shown[0][1];
  assert.equal(options.body, 'A new update');
  assert.equal(options.data, 'https://studio.example.test/studio?fmsView=notifications&fmsWorkspace=' + workspace);
  await current.event('push', {data: {json: () => ({path: '//malicious.example.test', workspace: 'bad'})}});
  assert.equal(current.shown[1][1].data, 'https://studio.example.test/?fmsView=notifications');
});
test('clicking a push reuses the Studio window and rejects an external target', async () => {
  let focused = 0;
  let target;
  const current = worker([{
    url: 'https://studio.example.test/studio?fmsWorkspace=old',
    navigate: async url => {target = url; return {focus: async () => focused++};},
  }]);
  let closed = 0;
  const destination = 'https://studio.example.test/studio?fmsView=notifications&fmsWorkspace=new';
  await current.event('notificationclick', {notification: {data: destination, close: () => closed++}});
  assert.equal(closed, 1);
  assert.equal(target, destination);
  assert.equal(focused, 1);
  assert.equal(current.opened.length, 0);
  await current.event('notificationclick', {notification: {data: 'https://malicious.example.test', close: () => {}}});
  assert.equal(current.opened.length, 0);
});
