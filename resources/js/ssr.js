import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { renderToString } from '@vue/server-renderer';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createSSRApp, h } from 'vue';
import { ZiggyVue, route as ziggyRoute } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer((page) =>
    createInertiaApp({
        // #region debug-point A:ssr-entry
        ...(fetch('http://127.0.0.1:7777/event', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                sessionId: 'pi-wise-analysis-500',
                runId: 'pre-fix',
                hypothesisId: 'A',
                location: 'resources/js/ssr.js:createServer',
                msg: '[DEBUG] SSR entry received page payload',
                data: { component: page?.component ?? null, url: page?.url ?? null },
                ts: Date.now(),
            }),
        }).catch(() => {}), {}),
        // #endregion
        page,
        render: renderToString,
        resolve: async (name) => {
            try {
                const component = await resolvePageComponent(
                    `./Pages/${name}.vue`,
                    import.meta.glob('./Pages/**/*.vue'),
                );
                // #region debug-point B:ssr-resolve-ok
                fetch('http://127.0.0.1:7777/event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sessionId: 'pi-wise-analysis-500',
                        runId: 'pre-fix',
                        hypothesisId: 'B',
                        location: 'resources/js/ssr.js:resolve',
                        msg: '[DEBUG] SSR resolve succeeded',
                        data: { name },
                        ts: Date.now(),
                    }),
                }).catch(() => {});
                // #endregion
                return component;
            } catch (error) {
                // #region debug-point B:ssr-resolve-fail
                fetch('http://127.0.0.1:7777/event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sessionId: 'pi-wise-analysis-500',
                        runId: 'pre-fix',
                        hypothesisId: 'B',
                        location: 'resources/js/ssr.js:resolve',
                        msg: '[DEBUG] SSR resolve failed',
                        data: { name, error: error?.message ?? String(error), stack: error?.stack ?? null },
                        ts: Date.now(),
                    }),
                }).catch(() => {});
                // #endregion
                throw error;
            }
        },
        setup({ App, props, plugin }) {
            // #region debug-point C:ssr-setup
            fetch('http://127.0.0.1:7777/event', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    sessionId: 'pi-wise-analysis-500',
                    runId: 'pre-fix',
                    hypothesisId: 'C',
                    location: 'resources/js/ssr.js:setup',
                    msg: '[DEBUG] SSR setup reached',
                    data: { component: page?.component ?? null },
                    ts: Date.now(),
                }),
            }).catch(() => {});
            // #endregion
            const ziggy = {
                ...page.props.ziggy,
            };

            globalThis.Ziggy = ziggy;
            globalThis.route = ziggyRoute;

            return createSSRApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue, ziggy);
        },
    }),
);
