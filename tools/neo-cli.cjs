#!/usr/bin/env node

/**
 * Neo Build CLI
 * A command-line tool for Drupal Neo build operations
 */

const { execSync } = require('child_process');
const http = require('http');
const path = require('path');
const colors = require('picocolors');
const prompts = require('@inquirer/prompts');

// Configuration
const CONFIG = {
  commands: {
    getDrushScopes: 'drush neo-scopes --format=json',
    setDrushDev: 'drush neo-dev-',
    drushNeo: 'drush neo',
    vite: 'vite'
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
    let env = `NEO_SCOPE=${scope} `;
    let viteCommand = CONFIG.commands.vite;
    if (target !== 'dev') {
      viteCommand += ' build && tsc';
    }
    execAndShow(`${env}${viteCommand}`);
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
