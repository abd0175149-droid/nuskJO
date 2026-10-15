import { describe, expect, it } from 'vitest';
import { installHistoryGuard, onPopState } from '@/utils/historyGuard';

// مستمع Inertia يُسجَّل بعد الحارس؛ ما يعترضه معالج صفحة لا يصل إليه
describe('historyGuard', () => {
    const target = new EventTarget();
    installHistoryGuard(target);
    const reached = [];
    target.addEventListener('popstate', () => reached.push('inertia'));
    const pop = () => target.dispatchEvent(new Event('popstate'));

    it('lets a page claim its own history steps before Inertia', () => {
        const release = onPopState(() => true);
        pop();
        expect(reached).toEqual([]);
        release();
        pop();
        expect(reached).toEqual(['inertia']);
    });

    it('passes through what the page does not claim', () => {
        reached.length = 0;
        const release = onPopState(() => false);
        pop();
        expect(reached).toEqual(['inertia']);
        release();
    });
});
