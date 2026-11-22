import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyFileSync, mkdirSync } from 'node:fs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = __dirname;
const OUT_DIR = resolve(ROOT, 'public/js/portal');

/**
 * Tabler (Bootstrap 5 UI kit) ships separate LTR and RTL stylesheets and the
 * portal picks one per request (portal.blade.php reads the locale), so they
 * cannot be inlined into app.css. Copy both into the build folder instead.
 */
function copyTablerCss(outDir) {
    const src = resolve(ROOT, 'node_modules/@tabler/core/dist/css');
    return {
        name: 'portal-copy-tabler-css',
        closeBundle() {
            mkdirSync(outDir, { recursive: true });
            for (const f of ['tabler.min.css', 'tabler.rtl.min.css']) {
                copyFileSync(resolve(src, f), resolve(outDir, f));
            }
        },
    };
}

/**
 * Client Portal — its own SPA (Tabler design, ported from stocky), built
 * separately from the admin.
 *
 * Fixed output names (app.js / app.css, no manifest): resources/views/portal.blade.php
 * loads them with a filemtime() query. Unlike the admin entry that is safe here
 * because the bundle has NO lazy chunks (inlineDynamicImports) — nothing else
 * imports the entry by URL, so a `?v=` query cannot fork the module graph.
 *
 * Output: public/js/portal/ (`npm run build` cleans public/js once, then
 * builds all four bundles into it).
 */
export default defineConfig({
    plugins: [vue(), copyTablerCss(OUT_DIR)],
    // Assets (icon fonts) are referenced from app.css by URL, so the base must
    // be the folder portal.blade.php serves the bundle from.
    base: '/js/portal/',
    publicDir: false,
    build: {
        outDir: OUT_DIR,
        emptyOutDir: true,
        cssCodeSplit: false,
        manifest: false,
        rollupOptions: {
            input: resolve(ROOT, 'resources/src/portal/main.js'),
            output: {
                inlineDynamicImports: true,
                entryFileNames: 'app.js',
                // One stylesheet named app.css; fonts keep their own names so
                // the four Tabler icon faces do not overwrite each other.
                assetFileNames: (info) => (String(info.name || '').endsWith('.css') ? 'app.css' : '[name].[ext]'),
            },
        },
    },
});
