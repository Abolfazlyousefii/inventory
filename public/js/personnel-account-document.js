(() => {
    const init = () => {
        const modal = document.getElementById('personnelDocumentModal');
        if (!modal) return;
        modal.querySelector('form').addEventListener('submit', () => {
            const button = modal.querySelector('[type="submit"]');
            button.disabled = true;
            button.textContent = 'در حال ثبت…';
        });
        if (modal.hasAttribute('data-personnel-reopen') && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
