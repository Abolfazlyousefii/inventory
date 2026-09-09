(() => {
    'use strict';

    const TOKEN_KEY = 'aria_preinvoice_reservation_token_v1';
    const LOCAL_DRAFT_KEY = 'aria_preinvoice_local_draft_create_v1';

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
            // A real local draft owns this token. Keep the browser token aligned
            // so restoring that draft continues the same reservation lifecycle.
            window.localStorage.setItem(TOKEN_KEY, draftToken);
            return;
        }

        // A bare reservation token without an actual recoverable local draft is
        // an orphan from a previous form/submission. Remove it before the create
        // page initializes so ensureReservationToken() generates a fresh UUID.
        window.localStorage.removeItem(TOKEN_KEY);
    } catch (error) {
        // Browser storage can be disabled. The normal create flow will still
        // generate an in-memory/form token and remains functional.
    }
})();
