// Bundles the bridge and the ESC/POS layout used by the app into one file served at /print-bridge/print-bridge.mjs.
import { build } from 'rolldown';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));

await build({
    input: `${root}bridge/print-bridge.mjs`,
    platform: 'node',
    resolve: { alias: { '@': `${root}resources/js` } },
    output: {
        file: `${root}public/print-bridge/print-bridge.mjs`,
        format: 'esm',
        minify: false,
    },
});
