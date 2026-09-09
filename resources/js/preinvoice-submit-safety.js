(() => {
    'use strict';

    const TOKEN_KEY = 'aria_preinvoice_reservation_token_v1';
    const LOCAL_DRAFT_KEY = 'aria_preinvoice_local_draft_create_v1';

    // Only the create form owns the browser-level reservation token. Edit
    // flows have their own token lifecycle and must not be touched here.
    const isCreateForm = window.location.pathname.startsWith('/preinvoice')
        && !window.PREINVOICE_BOOT?.isEdit
        && document.getElementById('orderForm')
        && document.getElementById('reservation_token');

    if (!isCreateForm) {
        return;
    }

    try {
        const rawDraft = window.localStorage.getItem(LOCAL_DRAFT_KEY);
        let draft = null;

        if (rawDraft) {
            try {
                draft = JSON.parse(rawDraft);
            } catch (error) {
                draft = null;
            }
        }

        const draftToken = String(draft?.reservation_token || '').trim();
        const hasRecoverableLocalDraft = Boolean(
            draft
            && draft.version === 1
            && draftToken
            && (
                draft.autosave_uuid
                || draft.customer?.id
                || draft.customer?.name
                || draft.customer?.mobile
                || Object.keys(draft.groupedSelections || {}).length > 0
            )
        );

        if (hasRecoverableLocalDraft) {
            // A real local draft owns this token. Keep both values aligned so
            // recovery continues with the reservation that belongs to it.
            window.localStorage.setItem(TOKEN_KEY, draftToken);
            return;
        }

        // A bare token without an actual recoverable draft is an orphan from a
        // previous form/submission. Reusing it can point a new form at a token
        // already converted to an official preinvoice. Remove it before
        // create.blade.php calls ensureReservationToken(); a fresh UUID will be
        // generated for the new form.
        window.localStorage.removeItem(TOKEN_KEY);
    } catch (error) {
        // Storage can be disabled by the browser. The create form remains
        // functional and will fall back to its normal token generation path.
    }
})();
