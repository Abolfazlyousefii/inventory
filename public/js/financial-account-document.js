(() => {
    const modal = document.querySelector('[data-financial-document-reopen]');
    if (modal && window.bootstrap?.Modal) bootstrap.Modal.getOrCreateInstance(modal).show();
})();
