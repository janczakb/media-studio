import assert from 'node:assert/strict';
import {cp, mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {test} from 'node:test';
import {chromium} from '@playwright/test';

test('compact popup, persistent preferences and Alt + right-click capture', {timeout: 35000}, async () => {
  const temporary = await mkdtemp(join(tmpdir(), 'flex-capture-'));
  const extension = join(temporary, 'extension');
  let browser;
  const server = createServer((request, response) => {
    if (request.url === '/thumb.svg') {
      response.setHeader('Content-Type', 'image/svg+xml');
      response.end('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="blue"/></svg>');
      return;
    }
    response.end('<!doctype html><title>Capture fixture</title><img src="/thumb.svg" alt="Sample image" data-flex-src="/original.png">');
  });

  try {
    await cp(fileURLToPath(new URL('../dist/chrome', import.meta.url)), extension, {recursive: true});
    const manifestPath = join(extension, 'manifest.json');
    const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
    manifest.host_permissions = ['http://127.0.0.1/*', 'https://www.instagram.com/*'];
    manifest.content_scripts = [{matches: manifest.host_permissions, js: ['content.js'], run_at: 'document_idle'}];
    await writeFile(manifestPath, JSON.stringify(manifest));
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;

    browser = await chromium.launchPersistentContext(join(temporary, 'profile'), {
      channel: 'chromium', headless: true, viewport: {width: 1000, height: 760},
      args: ['--disable-extensions-except=' + extension, '--load-extension=' + extension],
    });
    const worker = browser.serviceWorkers()[0] ?? await browser.waitForEvent('serviceworker', {timeout: 10000});
    const extensionUrl = 'chrome-extension://' + new URL(worker.url()).host;
    const popup = await browser.newPage();
    const errors = [];
    popup.on('pageerror', error => errors.push(error.message));
    await popup.goto(extensionUrl + '/popup.html');
    const preferences = popup.getByRole('button', {name: 'Preferences', exact: true});
    await preferences.waitFor();
    const {width, height} = await popup.locator('.fc-menu').boundingBox();
    assert.deepEqual([width, height], [270, 256]);

    await preferences.click();
    const settings = popup.locator('.fc-popup-settings');
    const settingsSize = await settings.boundingBox();
    assert.deepEqual([settingsSize.width, settingsSize.height], [432, 600]);
    const cards = await popup.locator('.fc-drag-choice-picture').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().width));
    assert.ok(cards.length === 3 && cards.every(card => card > 100));
    await popup.getByRole('button', {name: 'JPEG', exact: true}).click();
    const shortcut = popup.getByRole('textbox', {name: 'Batch save', exact: true});
    await shortcut.press('Alt+7');
    await popup.waitForFunction(async () => {
      const {preferences} = await chrome.storage.local.get('preferences');
      return preferences.screenshotFormat === 'jpeg' && preferences.shortcuts.collect === 'Alt + 7';
    });
    await shortcut.press('Alt+0');
    await popup.getByRole('alert').getByText('This shortcut is already assigned.').waitFor();

    const source = await browser.newPage();
    await source.goto(origin);
    const image = source.locator('img');
    await image.evaluate(node => node.decode());
    const count = browser.pages().length;
    const capture = browser.waitForEvent('page', {timeout: 6000});
    await image.click({button: 'right', modifiers: ['Alt']});
    const manager = await capture;
    await manager.waitForURL(extensionUrl + '/manager.html?batch=*');
    await manager.getByText('Sample image', {exact: true}).first().waitFor();
    assert.equal(browser.pages().length, count + 1);

    // The batch action opens the real toolbar popup, routed to connection.
    await manager.getByRole('button', {name: 'Connect Studio', exact: true}).click();
    let connectionPopupReady = false;
    for (let attempt = 0; attempt < 60 && !connectionPopupReady; attempt++) {
      connectionPopupReady = await worker.evaluate(async () => {
        const contexts = await chrome.runtime.getContexts({contextTypes: ['POPUP']});
        const {['capture.popupRoute']: route} = await chrome.storage.session.get('capture.popupRoute');
        return contexts.length > 0 && !route;
      });
      if (!connectionPopupReady) await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.ok(connectionPopupReady, 'native toolbar popup consumed the connection route');

    const response = await worker.evaluate(async url => {
      const [tab] = await chrome.tabs.query({url: 'http://127.0.0.1/*'});
      return chrome.tabs.sendMessage(tab.id, {type: 'content.contextAsset', url: url + '/thumb.svg'});
    }, origin);
    assert.equal(response.data.url, origin + '/original.png');
    const reel = 'https://www.instagram.com/reel/Video01/';
    const mp4 = 'https://scontent.example.cdninstagram.com/reel.mp4';
    const embed = JSON.stringify(JSON.stringify({gql_data: {shortcode_media: {
      shortcode: 'Video01', is_video: true, video_url: mp4,
    }}}));
    let embedRequests = 0;
    await browser.route('https://www.instagram.com/**', route => {
      if (route.request().url() === reel + 'embed/') {
        embedRequests++;
        return route.fulfill({contentType: 'text/html', body: `<script>init({"contextJSON":${embed}})</script>`});
      }
      return route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Instagram fixture</title>'});
    });
    const instagram = await browser.newPage();
    await instagram.goto(reel);
    await instagram.bringToFront();
    const results = browser.waitForEvent('page', {timeout: 8000});
    const collected = await popup.evaluate(() => chrome.runtime.sendMessage({type: 'collect'}));
    assert.equal(collected.ok, true);
    const instagramBatch = await results;
    await instagramBatch.waitForURL(extensionUrl + '/manager.html?batch=*');
    await instagramBatch.getByText('Instagram Video01', {exact: true}).waitFor();
    assert.equal(embedRequests, 1);
    assert.deepEqual(errors, []);
  } finally {
    await browser?.close();
    if (server.listening) await new Promise(resolve => server.close(resolve));
    await rm(temporary, {recursive: true, force: true});
  }
});
