import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');
const api = join(root, 'apps', 'api');
const web = join(root, 'apps', 'web');
const cache = join(here, '.cache');

const DB = process.env.E2E_DB || 'erp_saas_e2e_test';
const API_PORT = Number(process.env.E2E_API_PORT || 8001);
const WEB_PORT = Number(process.env.E2E_WEB_PORT || 3005);
const CONTAINER = process.env.E2E_PG_CONTAINER || 'erp_postgres';
const PHP = process.env.E2E_PHP || 'php';

const args = process.argv.slice(2);
const mode = args.includes('--up') ? 'up' : args.includes('--down') ? 'down' : args.includes('--reset') ? 'reset' : 'all';
const playwrightArgs = args.filter((a) => !a.startsWith('--up') && !a.startsWith('--down') && !a.startsWith('--reset') && a !== '--keep');
const keep = args.includes('--keep');

if (!DB.endsWith('_test') || DB === 'erp_saas') {
  console.error(`La base ${DB} no termina en _test: no se toca.`);
  process.exit(1);
}

if ([3000, 8000].includes(API_PORT) || [3000, 8000].includes(WEB_PORT)) {
  console.error('Los puertos 3000 y 8000 son de otro proyecto: usa otros.');
  process.exit(1);
}

const apiEnv = {
  ...process.env,
  APP_ENV: 'local',
  APP_DEBUG: 'true',
  APP_URL: `http://localhost:${API_PORT}`,
  FRONTEND_URL: `http://localhost:${WEB_PORT}`,
  DB_DATABASE: DB,
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array',
  E2E_WEB_ORIGIN: `http://localhost:${WEB_PORT}`,
};

function run(cmd, cmdArgs, options = {}) {
  const result = spawnSync(cmd, cmdArgs, { stdio: options.quiet ? 'pipe' : 'inherit', shell: false, encoding: 'utf8', ...options });

  if (result.status !== 0 && !options.allowFail) {
    console.error(`Falló: ${cmd} ${cmdArgs.join(' ')}`);
    if (options.quiet) console.error(result.stdout, result.stderr);
    process.exit(result.status || 1);
  }

  return result;
}

function psql(sql, allowFail = false) {
  return run('docker', ['exec', CONTAINER, 'psql', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1', '-c', sql], { quiet: true, allowFail });
}

function portBusy(port) {
  const out = run('powershell', ['-NoProfile', '-Command', `(Get-NetTCPConnection -LocalPort ${port} -State Listen -ErrorAction SilentlyContinue | Measure-Object).Count`], { quiet: true, allowFail: true });

  return Number(String(out.stdout).trim() || 0) > 0;
}

function killPort(port, pattern) {
  if (process.platform !== 'win32') {
    run('sh', ['-c', `lsof -ti tcp:${port} | xargs -r kill -9`], { quiet: true, allowFail: true });
    return;
  }

  const ps = `Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match '${pattern}' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`;
  run('powershell', ['-NoProfile', '-Command', ps], { quiet: true, allowFail: true });
}

function stopServers() {
  killPort(API_PORT, `localhost:${API_PORT}.*router\\.php`);
  killPort(WEB_PORT, `next.* dev .*-p ${WEB_PORT}`);
  killPort(WEB_PORT, `next-server.*${WEB_PORT}|start-server.*${WEB_PORT}`);
}

function dropDatabase() {
  psql(`DROP DATABASE IF EXISTS ${DB} WITH (FORCE)`, true);
}

async function waitFor(url, label, seconds = 240) {
  const until = Date.now() + seconds * 1000;

  while (Date.now() < until) {
    try {
      const res = await fetch(url);
      if (res.status < 500) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 1000));
  }

  throw new Error(`${label} no respondió en ${seconds} s (${url}).`);
}

function prepareDatabase() {
  console.log(`\n1/4 Base temporal ${DB} (contenedor ${CONTAINER})`);
  dropDatabase();
  psql(`CREATE DATABASE ${DB}`);

  const shown = run(PHP, ['artisan', 'config:show', 'database.connections.pgsql.database'], { cwd: api, env: apiEnv, quiet: true });
  if (!String(shown.stdout).includes(DB)) {
    console.error('Laravel no tomó la base temporal; no se corre migrate.');
    process.exit(1);
  }

  run(PHP, ['artisan', 'config:clear'], { cwd: api, env: apiEnv, quiet: true });
  run(PHP, ['artisan', 'migrate:fresh', '--seed', '--force'], { cwd: api, env: apiEnv, quiet: true });

  mkdirSync(cache, { recursive: true });
  const prepared = run(PHP, [join(here, 'support', 'prepare.php')], { cwd: api, env: apiEnv, quiet: true });
  writeFileSync(join(cache, 'data.json'), prepared.stdout);
  console.log('   Base lista con los datos de muestra y cuentas de prueba.');
}

function startServers() {
  console.log(`\n2/4 API en http://localhost:${API_PORT} y web en http://localhost:${WEB_PORT}`);

  if (portBusy(API_PORT) || portBusy(WEB_PORT)) {
    console.log('   Había servidores de una corrida anterior: se detienen.');
    stopServers();
  }

  const apiLog = join(cache, 'api.log');
  const webLog = join(cache, 'web.log');
  writeFileSync(apiLog, '');
  writeFileSync(webLog, '');

  const apiProc = spawn(PHP, ['-S', `localhost:${API_PORT}`, join(here, 'support', 'router.php')], { cwd: join(api, 'public'), env: apiEnv, detached: true, stdio: 'ignore', windowsHide: true });
  apiProc.unref();

  const webEnv = { ...process.env, NEXT_PUBLIC_API_URL: `http://localhost:${API_PORT}/api`, NEXT_PUBLIC_SITE_URL: `http://localhost:${WEB_PORT}`, NEXT_TELEMETRY_DISABLED: '1' };
  const nextBin = join(web, 'node_modules', 'next', 'dist', 'bin', 'next');
  if (process.env.CI) {
    console.log('   Compilando la web (una sola vez)...');
    const build = spawnSync(process.execPath, [nextBin, 'build'], { cwd: web, env: webEnv, stdio: 'inherit' });
    if (build.status !== 0) throw new Error('La web no se pudo compilar.');
  }
  const webProc = spawn(process.execPath, [nextBin, process.env.CI ? 'start' : 'dev', '-p', String(WEB_PORT)], { cwd: web, env: webEnv, detached: true, stdio: 'ignore', windowsHide: true });
  webProc.unref();

  writeFileSync(join(cache, 'pids.json'), JSON.stringify({ api: apiProc.pid, web: webProc.pid }));
}

async function main() {
  if (mode === 'down') {
    stopServers();
    dropDatabase();
    console.log('Servidores detenidos y base temporal borrada.');
    return;
  }

  if (mode === 'reset') {
    prepareDatabase();
    return;
  }

  prepareDatabase();
  startServers();
  await waitFor(`http://localhost:${API_PORT}/api/modules`, 'La API');
  await waitFor(`http://localhost:${WEB_PORT}/login`, 'La web');
  console.log('   Servidores listos.');

  if (mode === 'up') {
    console.log('\nQuedan encendidos. Corre «npx playwright test» y al terminar «node run.mjs --down».');
    return;
  }

  console.log('\n3/4 Recorridos con Playwright (escritorio y celular)');
  const result = spawnSync(process.execPath, [join(here, 'node_modules', '@playwright', 'test', 'cli.js'), 'test', ...playwrightArgs], {
    cwd: here,
    stdio: 'inherit',
    env: { ...apiEnv, E2E_API: `http://localhost:${API_PORT}/api`, E2E_WEB: `http://localhost:${WEB_PORT}`, E2E_API_DIR: api, E2E_PHP: PHP },
  });

  console.log('\n4/4 Limpieza');
  if (!keep) {
    stopServers();
    dropDatabase();
    console.log('   Servidores detenidos y base temporal borrada.');
  } else {
    console.log('   --keep: servidores y base quedan para revisar.');
  }

  process.exit(result.status ?? 1);
}

main().catch((error) => {
  console.error(error.message);
  if (!keep) {
    stopServers();
    dropDatabase();
  }
  process.exit(1);
});
