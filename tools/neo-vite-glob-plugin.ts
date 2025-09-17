import { Plugin } from 'vite';
import { glob } from 'glob';
import path from 'path';
import fs from 'fs';

interface CssGlobOptions {
  /**
   * Root directory to resolve globs relative to
   * @default process.cwd()
   */
  root?: string;
  /**
   * Whether to sort matched files alphabetically
   * @default true
   */
  sort?: boolean;
}

export default function cssGlobPlugin(options: CssGlobOptions = {}): Plugin {
  const { root = process.cwd(), sort = true } = options;

  return {
    name: 'css-glob-import',
    enforce: 'pre',
    async transform(code: string, id: string) {
      // Only process .css files
      if (!id.endsWith('.css')) {
        return null;
      }

      if (id !== '/var/www/html/web/themes/front/src/css/front.css') {
        return null;
      }

      // Look for @import statements with glob patterns
      const importRegex = /@import\s+['"]([^'"]*\*[^'"]*)['"];?/g;
      let match;
      let hasGlobs = false;
      let transformedCode = code;

      while ((match = importRegex.exec(code)) !== null) {
        const [fullMatch, globPattern] = match;
        hasGlobs = true;

        // Resolve the glob pattern relative to the current file's directory
        const currentFileDir = path.dirname(id);
        const absoluteGlobPattern = path.resolve(currentFileDir, globPattern);

        try {
          // Find matching files
          let matchedFiles = await glob(absoluteGlobPattern, {
            cwd: root,
            absolute: false,
            nodir: true,
          });

          // Filter to only include .css files
          matchedFiles = matchedFiles.filter(file => file.endsWith('.css'));

          // Sort files if requested
          if (sort) {
            matchedFiles.sort();
          }

          // Convert absolute paths back to relative paths from the current file
          const relativeImports = matchedFiles.map(file => {
            const absolutePath = path.resolve(root, file);
            const relativePath = path.relative(currentFileDir, absolutePath);
            return relativePath.startsWith('.') ? relativePath : `./${relativePath}`;
          });

          // Generate individual @import statements
          const importStatements = relativeImports
            .map(relativePath => `@import '${relativePath.replace(/\\/g, '/')}';`)
            .join('\n');

          // Replace the glob import with individual imports
          transformedCode = transformedCode.replace(fullMatch, importStatements);

          // Add file dependencies for HMR
          relativeImports.forEach(file => {
            const absolutePath = path.resolve(currentFileDir, file);
            if (fs.existsSync(absolutePath)) {
              this.addWatchFile(absolutePath);
            }
          });

        } catch (error) {
          this.warn(`Failed to resolve glob pattern "${globPattern}": ${error}`);
          // Keep the original import if glob resolution fails
        }
      }

      return hasGlobs ? { code: transformedCode, map: null } : null;
    },

    // Handle HMR updates when glob-matched files change
    handleHotUpdate({ file, server }) {
      if (file.endsWith('.css')) {
        // Find all CSS files that might import this file via glob
        const cssModules = Array.from(server.moduleGraph.fileToModulesMap.keys())
          .filter(id => id.endsWith('.css'));

        // Invalidate modules that might have glob imports affecting this file
        cssModules.forEach(id => {
          const module = server.moduleGraph.getModuleById(id);
          if (module) {
            server.reloadModule(module);
          }
        });
      }
    },
  };
}
