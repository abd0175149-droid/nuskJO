import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: key,
        // مضيف الصفحة نفسها ما لم يُضبط VITE_REVERB_HOST: النظام يعمل على أكثر من نطاق
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });
} else {
    console.warn('[Echo] VITE_REVERB_APP_KEY not set — WebSocket disabled');
}
