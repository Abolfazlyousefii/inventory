(() => {
 'use strict';
 const dialog = document.getElementById('pd-history');
 if (!dialog) return;
 const opener = document.querySelector('[data-pd-history]');
 opener?.addEventListener('click', () => dialog.showModal());
 dialog.querySelector('[data-pd-close]')?.addEventListener('click', () => dialog.close());
 dialog.addEventListener('click', event => { if(event.target !== dialog) return; const box=dialog.getBoundingClientRect(); if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom) dialog.close(); });
 dialog.addEventListener('close', () => opener?.focus());
})();
