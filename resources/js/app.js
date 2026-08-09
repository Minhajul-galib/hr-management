import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue, route as ziggyRoute } from '../../vendor/tightenco/ziggy';
import VueApexCharts from "vue3-apexcharts";

// Polyfill for crypto.randomUUID for non-secure contexts (HTTP)
if (typeof window !== 'undefined' && (!window.crypto || !window.crypto.randomUUID)) {
    if (!window.crypto) {
        window.crypto = {
            getRandomValues: (arr) => {
                for (let i = 0; i < arr.length; i++) {
                    arr[i] = Math.floor(Math.random() * 256);
                }
                return arr;
            }
        };
    }
    window.crypto.randomUUID = function() {
        return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
            (c ^ (window.crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16)
        );
    };
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const ziggy = props?.initialPage?.props?.ziggy;
        if (typeof globalThis !== 'undefined' && ziggy) {
            globalThis.Ziggy = ziggy;
            if (typeof globalThis.route !== 'function') {
                globalThis.route = ziggyRoute;
            }
        }

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, ziggy)
            .use(VueApexCharts)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
