#!/usr/bin/env node

/**
 * Neo Build CLI
 * A command-line tool for Drupal Neo build operations
 */

const { execSync } = require('child_process');
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

// CLI state
const state = {
  scopes: null,
  target: process.env.npm_config_target || null,
  scope: process.env.npm_config_scope || null,
  all: process.env.npm_config_all || false,
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
        process.exit(1);
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
