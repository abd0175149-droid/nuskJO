import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import axios from 'axios';
import '@fontsource/tajawal/300.css';
import '@fontsource/tajawal/400.css';
import '@fontsource/tajawal/500.css';
import '@fontsource/tajawal/700.css';
import '@fontsource/tajawal/800.css';
import '@fontsource/inter/300.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '../css/app.css';
import './echo';
import { installHistoryGuard } from './utils/historyGuard';
import { installStepUpInterceptor, installStepUpVisits } from './composables/useStepUp';

// Axios global setup: CSRF token + credentials
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;
const csrfToken = document.querySelector('meta[name="csrf-token"]');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken.content;
}

// قبل Inertia: صفحات تدير خطواتها في history تعترض الرجوع قبله (utils/historyGuard.js)
installHistoryGuard();

// العامل الثاني والتحقق المعزّز (E27): 423 تفتح نافذة التأكيد ثم يُعاد الطلب، وجلسة لم تجتز التحدّي أو صاحب
// صلاحية حساسة لم يسجّل بعد يُحوَّل إلى صفحته
installStepUpInterceptor(axios, {
    onMfaRequired: () => window.location.assign('/two-factor/challenge'),
    onEnrollRequired: () => window.location.assign('/profile/security'),
});
installStepUpVisits(router);

createInertiaApp({
    title: (title) => title ? `${title} - NUSUK` : 'NUSUK',
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });
        app.use(plugin);
        app.use(ZiggyVue);
        app.mount(el);
    },
    progress: {
        color: '#3b82f6',
        showSpinner: true,
    },
});
