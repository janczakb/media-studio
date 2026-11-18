import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const packageDirectory = fileURLToPath(new URL('.', import.meta.url));
const output = new URL('../../docs/snippets/cli-download.mdx', import.meta.url);
const temporary = await mkdtemp(join(tmpdir(), 'flex-studio-pack-'));
try {
  const [pack] = JSON.parse(execFileSync('npm', ['pack', '--json', '--pack-destination', temporary], {cwd: packageDirectory, encoding: 'utf8'}));
  const archive = await readFile(join(temporary, pack.filename));
  const sha = createHash('sha256').update(archive).digest('hex');
  const snippet = '<a className="fms-cli-download" download="' + pack.filename + '" href="data:application/gzip;base64,' + archive.toString('base64') +
    '" data-sha256="' + sha + '"><span aria-hidden="true">↓</span> Download CLI</a>\n';
  await writeFile(output, snippet);
  console.log(pack.filename + ' · sha256 ' + sha);
} finally {
  await rm(temporary, {recursive: true, force: true});
}
