import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    const viteHost = env.VITE_HOST || '192.168.10.248';
    const vitePort = Number(env.VITE_PORT || 5179);

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.js',
                ssr: 'resources/js/ssr.js',
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
        ],
        optimizeDeps: {
            include: [
                'vuedraggable'
            ],
            force: true
        },
        server: {
            host: '0.0.0.0',
            port: vitePort,
            origin: `http://${viteHost}:${vitePort}`,
            cors: true,
            hmr: {
                protocol: 'ws',
                host: viteHost,
                port: vitePort,
                overlay: false
            }
        }
    };
});
