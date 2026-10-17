<script>
// نص رسالة واتساب منسّقاً (E16.7): عقد Vue تُبنى بـ h() من رموز useWaFormat، والنص يُضاف عقداً نصية يهرّبها Vue.
// لا HTML خام إطلاقاً (tests/js/noVHtml.test.js)، والروابط http/https فقط وتُفتح بلا وصول إلى الصفحة.
import { h } from 'vue';
import { parse } from '@/composables/whatsapp/useWaFormat.js';

const LINK_CLASS = 'underline underline-offset-2 break-all';

function inline(tokens) {
    return tokens.map((t) => {
        switch (t.type) {
            case 'text':
                return t.text;
            case 'code':
                return h('code', { class: 'px-1 rounded bg-black/10 dark:bg-white/10 font-mono text-[0.85em]', dir: 'ltr' }, t.text);
            case 'link':
                return h('a', { href: t.href, target: '_blank', rel: 'noopener noreferrer nofollow', class: LINK_CLASS, dir: 'ltr' }, t.text);
            default:
                return h(t.type, inline(t.children));
        }
    });
}

function lines(list) {
    return list.flatMap((l, i) => (i ? [h('br'), ...inline(l)] : inline(l)));
}

function block(b, key) {
    switch (b.type) {
        case 'pre':
            return h('pre', { key, dir: 'ltr', class: 'my-1 p-2 rounded-lg bg-black/10 dark:bg-white/10 font-mono text-[0.85em] whitespace-pre-wrap break-words' }, b.text);
        case 'quote':
            return h('blockquote', { key, dir: 'auto', class: 'my-1 ps-2 border-s-2 border-current/40 opacity-90 [unicode-bidi:plaintext]' }, lines(b.lines));
        case 'ul':
        case 'ol':
            return h(b.type, { key, dir: 'auto', class: (b.type === 'ul' ? 'list-disc' : 'list-decimal') + ' ps-5 my-1 [unicode-bidi:plaintext]' },
                b.items.map((item, i) => h('li', { key: i }, inline(item))));
        default:
            return h('p', { key, dir: 'auto', class: 'whitespace-pre-wrap break-words [unicode-bidi:plaintext]' }, lines(b.lines));
    }
}

export default {
    name: 'WaText',
    props: { text: { type: String, default: '' } },
    setup(props) {
        return () => h('div', { class: 'space-y-0.5 break-words' }, parse(props.text).map((b, i) => block(b, i)));
    },
};
</script>
