import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import Components from 'unplugin-vue-components/vite';
import { BootstrapVueNextResolver } from 'bootstrap-vue-next';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/sass/app.scss',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        Components({
            resolvers: [BootstrapVueNextResolver()],
            dts: false,
        }),
    ],
    build: {
        chunkSizeWarningLimit: 2500,
        rollupOptions: {
            onwarn(warning, warn) {
                const msg = (warning.message || '') + ' ' + (warning.id || '');
                if (msg.includes('bootstrap-vue-next') && msg.includes('annotation')) {
                    return;
                }
                warn(warning);
            },
        },
    },
});
