// jsdom بلا showModal/close لعنصر <dialog>: محاكاة دنيا تكفي لاختبار AppModal (الفتح والإغلاق وحدث cancel)
if (typeof HTMLDialogElement !== 'undefined') {
    const proto = HTMLDialogElement.prototype;
    if (!proto.showModal) {
        proto.showModal = function showModal() {
            this.setAttribute('open', '');
        };
    }
    if (!proto.show) {
        proto.show = function show() {
            this.setAttribute('open', '');
        };
    }
    if (!proto.close) {
        proto.close = function close() {
            this.removeAttribute('open');
            this.dispatchEvent(new Event('close'));
        };
    }
}
