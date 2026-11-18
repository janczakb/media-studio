#!/usr/bin/env node
import {createWriteStream} from 'node:fs';
import {link, mkdtemp, rm} from 'node:fs/promises';
import {dirname, join, resolve} from 'node:path';
import {Readable} from 'node:stream';
import {pipeline} from 'node:stream/promises';
import {parseArgs} from 'node:util';
import {importPlan} from './import-plan.mjs';
import {StudioClient} from './studio-client.mjs';

const help = `FLEX Media Studio CLI

  flex-studio import <directory> --workspace <ULID> [--parent <ULID>]
      [--manifest <jsonl> | --pattern <regexp>] [--mode rebuild|keep]
      [--dry-run] [--concurrency <1-8>] [--direct] [--json]
  flex-studio search <query> --workspace <ULID> [--cursor <cursor>] [--json]
  flex-studio download <entry> <output-file> --workspace <ULID> [--version <ULID>]

Set FLEX_STUDIO_URL to the complete Studio MCP endpoint.
Set FLEX_STUDIO_TOKEN to the managed token for this client.
The dry run needs neither an endpoint nor a token.`;

const common = {workspace: {type: 'string'}, json: {type: 'boolean'}, help: {type: 'boolean', short: 'h'}};
const commands = {
  import: {...common, parent: {type: 'string'}, manifest: {type: 'string'}, pattern: {type: 'string'},
    mode: {type: 'string'}, 'dry-run': {type: 'boolean'}, concurrency: {type: 'string'}, direct: {type: 'boolean'}},
  search: {...common, cursor: {type: 'string'}},
  download: {...common, version: {type: 'string'}},
};

function ulid(value, field, required = true) {
  if (value == null && !required) return null;
  if (!/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i.test(value ?? '')) throw new Error(field + ' must be a ULID.');
  return value.toUpperCase();
}

function print(record, json) {
  if (json) {
    process.stdout.write(JSON.stringify(record) + '\n');
    return;
  }
  if (record.status === 'planned') {
    console.log(record.source + ' → ' + record.target +
      (record.variants.length ? '  [' + record.variants.join(', ') + ']' : '') +
      (record.skipped.length ? '  (skip ' + record.skipped.join(', ') + ')' : ''));
  } else if (record.status === 'uploaded') {
    console.log('✓ ' + record.target + '  ' + record.entry);
  } else {
    console.error('× ' + record.target + '  ' + record.message);
  }
}

async function runImport(root, values) {
  const mode = values.mode ?? 'rebuild';
  const plan = importPlan(root, {manifest: values.manifest, pattern: values.pattern, mode});
  const concurrency = Number(values.concurrency ?? 2);
  if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 8) throw new Error('Concurrency must be between 1 and 8.');
  if (values['dry-run']) {
    for await (const group of plan) {
      print({status: 'planned', id: group.id, source: group.source.path, target: [...group.folder, group.target].join('/'),
        variants: group.variants.map(file => file.path), skipped: group.skipped, roles: group.roles}, values.json);
    }
    return;
  }

  const workspace = ulid(values.workspace, '--workspace');
  const base = ulid(values.parent, '--parent', false);
  const client = new StudioClient(process.env.FLEX_STUDIO_URL, process.env.FLEX_STUDIO_TOKEN);
  const policy = await client.tool('studio_get_upload_policy', {workspace});
  const transferPaths = new Map();
  const path = tool => {
    if (!transferPaths.has(tool)) transferPaths.set(tool, client.tool(tool, {workspace}).then(result => result.uploadPath));
    return transferPaths.get(tool);
  };
  const paths = {ordinary: () => path('studio_prepare_file_upload'), bundle: () => path('studio_prepare_image_import')};
  const pending = new Set();
  try {
    for await (const group of plan) {
      const target = [...group.folder, group.target].join('/');
      const task = (async () => {
        try {
          const parent = await client.folder(workspace, base, group.folder);
          const result = await client.upload(workspace, parent, group, policy, paths, Boolean(values.direct));
          print({status: 'uploaded', id: group.id, target, entry: result.id}, values.json);
        } catch (error) {
          print({status: 'failed', id: group.id, target, message: error.message}, values.json);
          process.exitCode = 1;
        }
      })();
      pending.add(task);
      void task.then(() => pending.delete(task));
      if (pending.size >= concurrency) await Promise.race(pending);
    }
  } finally {
    await Promise.all(pending);
  }
}

async function download(client, workspace, entry, target, version) {
  const {downloadPath} = await client.tool('studio_prepare_file_download', {workspace, entry, version});
  const output = resolve(target);
  const stage = await mkdtemp(join(dirname(output), '.flex-download-'));
  const file = join(stage, 'download');
  try {
    const response = await client.download(downloadPath);
    await pipeline(Readable.fromWeb(response.body), createWriteStream(file, {flags: 'wx', mode: 0o600}));
    await link(file, output);
  } finally {
    await rm(stage, {recursive: true, force: true});
  }
  return output;
}

async function run() {
  const [command, ...args] = process.argv.slice(2);
  if (!command || ['help', '--help', '-h'].includes(command)) {
    console.log(help);
    return;
  }
  if (!Object.hasOwn(commands, command)) throw new Error('Unknown command. Run flex-studio --help.');
  const {values, positionals} = parseArgs({args, options: commands[command], allowPositionals: true});
  if (values.help) {
    console.log(help);
    return;
  }
  if (positionals.length !== (command === 'download' ? 2 : 1)) throw new Error('Wrong number of arguments. Run flex-studio --help.');
  if (command === 'import') {
    await runImport(positionals[0], values);
    return;
  }

  const workspace = ulid(values.workspace, '--workspace');
  const client = new StudioClient(process.env.FLEX_STUDIO_URL, process.env.FLEX_STUDIO_TOKEN);
  if (command === 'search') {
    const result = await client.tool('studio_search_files', {workspace, q: positionals[0], cursor: values.cursor});
    if (values.json) console.log(JSON.stringify(result));
    else {
      for (const item of result.items) console.log(item.id + '  ' + item.name);
      if (result.nextCursor) console.log('Next cursor: ' + result.nextCursor);
    }
  } else {
    const entry = ulid(positionals[0], 'Entry');
    const output = await download(client, workspace, entry, positionals[1], ulid(values.version, '--version', false));
    console.log(values.json ? JSON.stringify({id: entry, output}) : output);
  }
}

run().catch(error => {
  console.error('flex-studio: ' + error.message);
  process.exitCode = 1;
});
