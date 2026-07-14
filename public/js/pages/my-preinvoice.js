(function () {
  if (window.__myPreinvoiceBound) return;
  window.__myPreinvoiceBound = true;

  function setCardOpen(card, open) {
    if (!card) return;
    const details = card.querySelector('[data-document-details]');
    if (!details) return;
    card.classList.toggle('is-open', open);
    details.hidden = !open;
    card.querySelectorAll('[data-document-toggle]').forEach((toggle) => {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      const text = toggle.querySelector('.document-toggle-text');
      if (text) text.textContent = open ? 'بستن' : 'جزئیات';
    });
  }

  function closeOtherCards(activeCard) {
    document.querySelectorAll('[data-document-card].is-open').forEach((card) => {
      if (card !== activeCard) setCardOpen(card, false);
    });
  }

  document.addEventListener('click', function (event) {
    const trigger = event.target.closest('[data-document-toggle]');
    if (!trigger) return;
    event.preventDefault();
    const card = trigger.closest('[data-document-card]');
    if (!card) return;
    const nextOpen = !card.classList.contains('is-open');
    closeOtherCards(card);
    setCardOpen(card, nextOpen);
    if (nextOpen && window.matchMedia('(max-width: 767.98px)').matches) {
      requestAnimationFrame(() => card.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-document-card].is-open').forEach((card) => setCardOpen(card, false));
  });
}());
