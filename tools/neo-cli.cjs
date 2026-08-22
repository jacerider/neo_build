#!/usr/bin/env node

/**
 * Neo Build CLI
 * A command-line tool for Drupal Neo build operations
 */

const { execSync } = require('child_process');
const fs = require('fs');
const http = require('http');
const path = require('path');
const colors = require('picocolors');
const prompts = require('@inquirer/prompts');

// Configuration
const CONFIG = {
  commands: {
    getDrushScopes: 'drush neo-scopes --format=json',
    getDrushStatus: 'drush neo-status --format=json',
    setDrushDev: 'drush neo-dev-',
    drushNeo: 'drush neo',
    vite: 'vite'
  },
  // Drupal core owns the Nightwatch runner; we only drive it.
  nightwatch: {
    coreDir: 'web/core',
    runner: 'yarn test:nightwatch',
  }
};

// Positional arguments ("npm start -- prod front") work on every npm version;
// npm_config_* flags ("npm start --target=prod") are kept for compatibility.
const argv = process.argv.slice(2).filter((arg) => arg !== '--');
const argvArgs = argv.filter((arg) => !arg.startsWith('-'));
const argvFlags = argv.filter((arg) => arg.startsWith('-'));

// CLI state
const state = {
  scopes: null,
  target: process.env.npm_config_target || argvArgs[0] || null,
  scope: process.env.npm_config_scope || argvArgs[1] || null,
  all: process.env.npm_config_all || argvArgs[0] === 'all' || false,
  force: process.env.npm_config_force || argvFlags.includes('--force') || false,
  // `neo test [tag]` runs Nightwatch rather than a build.
  test: argvArgs[0] === 'test',
  tag: argvArgs[1] || null,
  noBuild: argvFlags.includes('--no-build'),
};

/**
 * Safely execute a command and return the output
 * @param {string} command - Command to execute
 * @param {Object} options - Options for execSync
 * @returns {string|null} Command output or null on error
 */
function safeExec(command, options = {}) {
  try {
    return execSync(command, { ...options }).toString();
  } catch (error) {
    if (options.throwError) {
      throw error;
    }
    return null;
  }
}

/**
 * Execute a command and display output
 * @param {string} command - Command to execute
 * @returns {boolean} Success status
 */
function execAndShow(command) {
  try {
    execSync(command, { stdio: 'inherit' });
    return true;
  } catch (error) {
    return false;
  }
}

function clear() {
  try {
    process.stdout.write('\x1Bc');
  } catch (error) {
    console.error('Error clearing screen:', error);
  }
}

/**
 * Get the dev server port from neo.json, falling back to the default.
 * @returns {number} The dev server port
 */
function getDevServerPort() {
  try {
    return require(path.resolve(process.cwd(), 'neo.json')).port || 5173;
  } catch (error) {
    return 5173;
  }
}

/**
 * Probe the Vite dev server. Any process answering on the port counts —
 * the dev server binds 0.0.0.0 inside the container, so localhost reaches it.
 * @returns {Promise<boolean>} True if a dev server is answering
 */
function devServerRunning() {
  return new Promise((resolve) => {
    const req = http.get({
      host: 'localhost',
      port: getDevServerPort(),
      path: '/@vite/client',
      timeout: 1000,
    }, (res) => {
      res.resume();
      resolve(true);
    });
    req.on('timeout', () => {
      req.destroy();
      resolve(false);
    });
    req.on('error', () => resolve(false));
  });
}

/**
 * Refuse a prod build while a dev server is live (unless --force).
 *
 * A prod build here would silently disconnect the running dev session:
 * it disables the Drupal-side dev state, rewrites neo.json (restarting the
 * dev server against prod config) and deletes the dev lock file. Anyone —
 * or any agent — asking for a prod build while the watcher is up almost
 * certainly doesn't need one.
 */
async function guardDevServer() {
  if (state.force) {
    return;
  }
  if (!(await devServerRunning())) {
    return;
  }
  const port = getDevServerPort();
  console.error(`
${colors.red('✘')} ${colors.cyan('[neo]')} ${colors.red(`Refusing to build: a Neo dev server is already running on port ${port}.`)}

  Asset changes are already live via HMR — no build is needed:
    - src TS/CSS, *.twig, *.component.yml and *.php edits are picked up automatically.
    - *.info.yml and *.libraries.yml edits regenerate neo.json and clear caches automatically.

  If a change is not showing, run ${colors.yellow('drush neo-status')} — the dev server serves
  ONE scope at a time and your change may belong to the other scope.

  A prod build now would disconnect the running dev session (its owner loses
  HMR without warning). If that is really what you want:

    ${colors.yellow('npm run deploy --force')}
`);
  process.exit(1);
}

/**
 * Get available scopes from Drupal
 * @returns {Object} Available scopes
 */
function getScopes() {
  if (!state.scopes) {
    const scopesJson = safeExec(CONFIG.commands.getDrushScopes);
    if (!scopesJson) {
      console.error(`${colors.red('[neo]')} Failed to fetch scopes`);
      process.exit(1);
    }
    state.scopes = JSON.parse(scopesJson);
  }
  return state.scopes;
}

/**
 * Set development mode
 * @param {string} mode - 'enable' or 'disable'
 */
function setDevMode(mode) {
  const command = `${CONFIG.commands.setDrushDev}${mode}`;
  execAndShow(command);
}

/**
 * Call for cleanup
 * @param {string} mode - 'enable' or 'disable'
 */
function cleanup() {
  const command = `drush neo-dev-cleanup`;
  execAndShow(command);
}

/**
 * Run Drupal Neo command for a scope and group
 * @param {string} scope - The scope
 * @param {string} group - The group (optional)
 */
function runDrushNeo(scope) {
  const command = `${CONFIG.commands.drushNeo} ${scope}`;
  execAndShow(command);
}

/**
 * Run Vite for production
 * @param {string} group - The group
 */
async function runViteProd() {
  await guardDevServer();
  clear();
  console.log(`\n${colors.cyan('[neo]')} ${colors.yellow('Building for production...')}`);
  for (const [scope, details] of Object.entries(state.scopes)) {
    cli('prod', scope);
  }
  cleanup();
  console.log(`\n${colors.green('✔')} ${colors.cyan('[neo]')} All builds completed successfully`);
}

/**
 * Run Vite for a specific scope and group
 * @param {string} scope - The scope
 * @param {string} target - 'dev' or 'prod'
 * @returns {Promise<void>}
 */
async function runVite(scope, target) {
  return new Promise((resolve, reject) => {
    // The scope reaches Vite through neo.json, which prepare rewrites per
    // scope before this runs. NEO_SCOPE was exported here as well and read by
    // nothing on either side of the boundary.
    let viteCommand = CONFIG.commands.vite;
    if (target !== 'dev') {
      viteCommand += ' build && tsc';
    }
    execAndShow(viteCommand);
    resolve();
  });
}

/**
 * Prompt for target if not provided
 * @returns {Promise<string>}
 */
async function promptForTarget() {
  if (state.target) return state.target;

  return prompts.select({
    message: 'Select target',
    choices: [
      {
        name: 'DEV',
        value: 'dev',
        description: 'Will enable hot module replacement for Neo assets.'
      },
      {
        name: 'PROD',
        value: 'prod',
        description: 'Will aggregate and compress Neo assets.'
      },
    ],
  }).catch(() => process.exit(1));
}

/**
 * Prompt for scope if not provided
 * @returns {Promise<string>}
 */
async function promptForScope() {
  if (state.scope) return state.scope;
  const options = Object.entries(state.scopes).map(([key, scope]) => ({
    name: scope.label,
    value: scope.id,
    description: scope.description,
  }));

  return prompts.select({
    message: 'Select scope',
    choices: options,
  }).catch(() => process.exit(1));
}

/**
 * Read the Neo build status from Drupal.
 * @returns {Object} The parsed status, or an empty object when unavailable
 */
function getNeoStatus() {
  const json = safeExec(CONFIG.commands.getDrushStatus);
  if (!json) {
    return {};
  }
  try {
    return JSON.parse(json);
  } catch (error) {
    return {};
  }
}

/**
 * Refuse to run browser tests against dev-server assets.
 *
 * Nightwatch drives a real browser against the real site, so it tests whatever
 * the page actually loads. In dev mode that is the Vite dev server rather than
 * the compiled dist/ output — and the dev server serves ONE scope at a time, so
 * the other scope's assets are missing entirely. Tests would be exercising
 * something other than what ships, in either direction: a green run that proves
 * nothing, or a red run caused by the harness.
 */
function guardTestAssets() {
  if (state.force) {
    return;
  }
  const status = getNeoStatus();

  // A live dev server belongs to whoever started it. Building would disconnect
  // their HMR session without warning, so this is refused outright rather than
  // repaired.
  if (status.dev_server_up) {
    console.error(`
${colors.red('✘')} ${colors.cyan('[neo]')} ${colors.red('Refusing to test: a Neo dev server is running.')}

  ${colors.yellow(status.status || 'Dev mode is active.')}

  Nightwatch drives a real browser, so it loads whatever the page serves — in
  dev mode that is the dev server's HMR output rather than the compiled
  ${colors.yellow('dist/')} files, and only for the one scope it is serving. The run would not
  be testing what ships.

  Building first would disconnect that dev session, so stop it yourself, then:

    ${colors.yellow('npm run deploy')}

  To test against the dev server deliberately:

    ${colors.yellow('npm start test --force')}
`);
    process.exit(1);
  }

  // Dev mode with no server just means stale dist/ assets. The build this
  // command runs by default restores them, so only refuse when it is skipped.
  if ((status.dev || status.lock) && state.noBuild) {
    console.error(`
${colors.red('✘')} ${colors.cyan('[neo]')} ${colors.red('Refusing to test: Neo is in DEV mode and --no-build was given.')}

  ${colors.yellow(status.status || 'Dev mode is active.')}

  The compiled assets the browser would load are stale, and ${colors.yellow('--no-build')} means
  nothing is going to rebuild them. Drop the flag to let this build first:

    ${colors.yellow('npm start test')}
`);
    process.exit(1);
  }
}

/**
 * Keep Nightwatch directories on CommonJS.
 *
 * Nightwatch files are written as CommonJS — that is what core's runner
 * requires them as, and what every example in core and contrib uses. But a
 * project root package.json with `"type": "module"` makes Node treat every .js
 * outside core/ as an ES module, so `module.exports` throws
 * "module is not defined in ES module scope".
 *
 * This is not a per-module problem to fix per module: Nightwatch loads all
 * discovered Commands and Assertions globally, regardless of --tag, so ONE
 * contrib module shipping CommonJS aborts the entire run. Renaming to .cjs is
 * not an option either, because the discovery glob only matches *.js.
 *
 * A package.json declaring `"type": "commonjs"` scopes the subtree back. This
 * writes that marker into any Nightwatch directory missing it — additive,
 * idempotent, and it restores the behaviour those files were always written
 * for. Composer strips it from contrib on update; the next run puts it back.
 */
function ensureNightwatchCommonJs() {
  let rootType = null;
  try {
    rootType = require(path.resolve(process.cwd(), 'package.json')).type;
  } catch (error) {
    return;
  }
  if (rootType !== 'module') {
    return;
  }

  let dirs = [];
  try {
    dirs = fs.globSync('web/{modules,themes,profiles}/**/tests/**/Nightwatch', {
      cwd: process.cwd(),
    });
  } catch (error) {
    // fs.globSync needs Node >= 22; skip the repair rather than fail the run.
    return;
  }

  const written = [];
  dirs.forEach((dir) => {
    const marker = path.resolve(process.cwd(), dir, 'package.json');
    if (fs.existsSync(marker)) {
      return;
    }
    fs.writeFileSync(marker, `{
  "//": "Written by \`neo test\`. Scopes this directory back to CommonJS, which Nightwatch files are written as. Without it a project root \\"type\\": \\"module\\" makes Node load them as ES modules and module.exports throws.",
  "type": "commonjs"
}
`);
    written.push(dir);
  });

  if (written.length) {
    console.log(`${colors.cyan('[neo]')} Restored CommonJS scope for ${written.length} Nightwatch director${written.length === 1 ? 'y' : 'ies'}:`);
    written.forEach((dir) => console.log(`  ${colors.yellow(dir)}`));
  }
}

/**
 * Ensure Drupal core's Nightwatch runner is installed and configured.
 *
 * Core drives Nightwatch through dotenv-safe, which requires the .env FILE to
 * exist even when every variable is already supplied by the environment (as
 * DDEV's selenium add-on does). dotenv never overrides an existing environment
 * variable, so the generated file is only a fallback.
 */
function ensureNightwatchPrereqs() {
  const coreDir = path.resolve(process.cwd(), CONFIG.nightwatch.coreDir);
  if (!fs.existsSync(coreDir)) {
    console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Could not find ${CONFIG.nightwatch.coreDir} from ${process.cwd()}.`);
    process.exit(1);
  }

  const envPath = path.join(coreDir, '.env');
  if (!fs.existsSync(envPath)) {
    fs.writeFileSync(envPath, `# Generated by \`neo test\`.
#
# Core runs Nightwatch through dotenv-safe, which requires this file to exist.
# DDEV's selenium add-on already exports the webdriver variables via
# web_environment, and dotenv does not override an existing environment
# variable — so everything here is only a fallback for non-DDEV setups.
DRUPAL_TEST_BASE_URL=http://web
DRUPAL_TEST_DB_URL=mysql://db:db@db/db
DRUPAL_TEST_WEBDRIVER_HOSTNAME=selenium-chrome
DRUPAL_TEST_WEBDRIVER_PORT=4444
DRUPAL_TEST_WEBDRIVER_PATH_PREFIX=/wd/hub
# Selenium runs as its own container, so nothing should autostart chromedriver.
DRUPAL_TEST_CHROMEDRIVER_AUTOSTART=false
DRUPAL_NIGHTWATCH_OUTPUT=reports/nightwatch
DRUPAL_NIGHTWATCH_IGNORE_DIRECTORIES=node_modules,vendor,.*,sites/*/files,sites/*/private,sites/simpletest
`);
    console.log(`${colors.cyan('[neo]')} Wrote ${colors.yellow('web/core/.env')} for the Nightwatch runner.`);
  }

  ensureNightwatchCommonJs();

  if (!fs.existsSync(path.join(coreDir, 'node_modules', '.bin', 'nightwatch'))) {
    console.log(`${colors.cyan('[neo]')} ${colors.yellow('Installing Drupal core JS dependencies (one time, this takes a few minutes)...')}`);
    // Core pins yarn via its packageManager field; corepack activates it.
    if (!execAndShow(`corepack enable && cd ${coreDir} && yarn install`)) {
      console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Failed to install core's JS dependencies.`);
      process.exit(1);
    }
  }
}

/**
 * Run Nightwatch.
 *
 * Core's runner discovers tests anywhere matching
 * **\/tests\/**\/Nightwatch/(Tests|Commands|Assertions|Pages), so modules need no
 * registration — a tag is how you scope a run to one of them.
 */
function runNightwatch() {
  guardTestAssets();

  if (!state.noBuild) {
    console.log(`${colors.cyan('[neo]')} ${colors.yellow('Building assets so the browser tests what ships...')}`);
    if (!execAndShow('npm run deploy')) {
      console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Build failed; not running tests against stale assets.`);
      process.exit(1);
    }
  }

  ensureNightwatchPrereqs();

  const coreDir = path.resolve(process.cwd(), CONFIG.nightwatch.coreDir);
  // With no tag, run everything except core's own suite.
  const filter = state.tag ? `--tag ${state.tag}` : '--skiptags core';
  console.log(`\n${colors.cyan('[neo]')} ${colors.yellow(`Running Nightwatch (${filter})...`)}\n`);
  const passed = execAndShow(`cd ${coreDir} && ${CONFIG.nightwatch.runner} ${filter}`);
  console.log(`\n${colors.cyan('[neo]')} Reports: ${colors.yellow('web/core/reports/nightwatch')}`);
  process.exit(passed ? 0 : 1);
}

/**
 * Initialize CLI setup
 */
function initializeCli() {
  // Initialize scopes.
  getScopes();

  // Count number of exits.
  let count = 0;
  // Silently catch SIGINT and exit.
  process.on('SIGINT', () => {
    if (count > 0) {
      process.exit(0);
    }
    count++;
  });
}

// `neo test` drives Nightwatch instead of a build, and needs no scopes.
if (state.test) {
  runNightwatch();
}

initializeCli();

/**
 * Run the CLI
 */
async function cli(target, scope) {
  try {

    // Run 'all' scope.
    if (state.all) {
      state.all = false;
      runViteProd();
      return;
    }

    // Interactive prompts
    state.target = target || await promptForTarget();

    // Guard top-level invocations against a live dev server. Re-entrant calls
    // (runViteProd() looping scopes, the prod rebuild after the dev server
    // exits) pass an explicit target and are guarded by runViteProd() itself.
    if (!target) {
      if (state.target === 'dev' && await devServerRunning()) {
        console.log(`${colors.cyan('[neo]')} A Neo dev server is already running on port ${getDevServerPort()}. Leaving it alone.`);
        process.exit(0);
      }
      if (state.target === 'prod') {
        await guardDevServer();
      }
    }

    state.scope = scope || await promptForScope();

    // Validate scope.
    if (!state.scopes[state.scope]) {
      console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Invalid scope: ${state.scope}. Available scopes are: ${Object.keys(state.scopes).join(', ')}.`);
      process.exit(1);
    }

    // Set dev mode based on target
    const devMode = state.target === 'prod' ? false : true;
    if (devMode) {
      clear();
      console.log(`${colors.cyan('[neo]')} ${colors.yellow('Build CLI')}\n`);
      setDevMode('enable');
    }
    else {
      setDevMode('disable');
    }

    // Run Drupal Neo command for each scope
    runDrushNeo(state.scope);

    runVite(state.scope, state.target).then(() => {
      if (!devMode) {
        // Prod build finished successfully — exit 0 so callers (ddev exec, CI)
        // don't report a false failure.
        process.exit(0);
      }
      else {
        runViteProd();
      }
    }).catch((error) => {
      console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Build failed:`, error.message);
      process.exit(1);
    });
  } catch (error) {
    console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Build failed:`, error.message);
    process.exit(1);
  }
}

// Start the CLI
cli();
