import { reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// إجراءات الصفوف (اعتماد، رفض، فتح للتعديل): زر الصف معطّل حتى يعود الطلب، فلا نقرة مزدوجة.
// والخادم نفسه يرفض الانتقال المكرر (HasApproval)؛ هذا لتجربة أوضح فقط.
export function useRowActions() {
    const busy = reactive(new Set());
    const page = usePage();

    const post = (url, data = {}, key = url) => {
        if (busy.has(key)) return;
        busy.add(key);
        router.post(url, data, { preserveScroll: true, onFinish: () => busy.delete(key) });
    };

    // فصل المهام: المنشئ لا يعتمد مستنده إلا المدير
    const mayApprove = (doc) => !!page.props.auth?.isAdmin || doc.created_by !== page.props.auth?.user?.id;

    return { busy, post, mayApprove };
}
