(() => {
    'use strict';

    if (!window.location.pathname.startsWith('/preinvoice')) {
        return;
    }

    const originalFetch = window.fetch.bind(window);
    const TOKEN_KEY = 'aria_preinvoice_reservation_token_v1';
    const LOCAL_DRAFT_KEY = 'aria_preinvoice_local_draft_create_v1';
    const AUTOSAVE_PATH = '/preinvoice/autosave';
    const AUTOSAVE_LATEST_PATH = '/preinvoice/autosave/latest';
    const RESERVATION_SYNC_PATH = '/preinvoice/api/reservations/sync';

    function normalize(value) {
        return String(value ?? '').trim();
    }

    function randomUuid() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
            const random = Math.random() * 16 | 0;
            const value = character === 'x' ? random : (random & 0x3 | 0x8);
            return value.toString(16);
        });
    }

    function readLocalDraft() {
        try {
            const raw = window.localStorage.getItem(LOCAL_DRAFT_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function writeLocalDraft(draft) {
        if (!draft) {
            return;
        }

        try {
            window.localStorage.setItem(LOCAL_DRAFT_KEY, JSON.stringify(draft));
        } catch (error) {
            // The visible form remains authoritative when storage is unavailable.
        }
    }

    function rotateReservationToken(oldToken, autosave = null) {
        const freshToken = randomUuid();

        try {
            window.localStorage.setItem(TOKEN_KEY, freshToken);
        } catch (error) {
            // Keep the DOM token usable even when storage is unavailable.
        }

        const input = document.getElementById('reservation_token');
        if (input && (!normalize(input.value) || normalize(input.value) === normalize(oldToken))) {
            input.value = freshToken;
        }

        const localDraft = readLocalDraft();
        if (localDraft && normalize(localDraft.reservation_token) === normalize(oldToken)) {
            localDraft.reservation_token = freshToken;
            if (autosave?.uuid) {
                localDraft.autosave_uuid = autosave.uuid;
            }
            if (autosave?.version) {
                localDraft.autosave_version = autosave.version;
            }
            writeLocalDraft(localDraft);
        }

        return freshToken;
    }

    function requestUrl(input) {
        try {
            const value = typeof input === 'string' ? input : input?.url;
            return new URL(value, window.location.origin);
        } catch (error) {
            return null;
        }
    }

    function requestMethod(input, init) {
        return String(init?.method || input?.method || 'GET').toUpperCase();
    }

    function parseJsonBody(init) {
        if (typeof init?.body !== 'string') {
            return null;
        }

        try {
            return JSON.parse(init.body);
        } catch (error) {
            return null;
        }
    }

    function withBody(init, body) {
        return {
            ...(init || {}),
            body: JSON.stringify(body),
        };
    }

    async function responseJson(response) {
        try {
            return await response.clone().json();
        } catch (error) {
            return null;
        }
    }

    async function activeServerAutosaveExists() {
        try {
            const response = await originalFetch(AUTOSAVE_LATEST_PATH, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return true;
            }

            const json = await response.json();
            return Boolean(json?.draft);
        } catch (error) {
            // Fail closed. Never rotate a token when we cannot prove that no
            // recoverable server-side draft exists.
            return true;
        }
    }

    async function retryAutosaveWithFreshToken(input, init, body) {
        if (await activeServerAutosaveExists()) {
            return null;
        }

        const oldToken = normalize(body.reservation_token);
        if (!oldToken) {
            return null;
        }

        const freshToken = rotateReservationToken(oldToken);
        const retryBody = {
            ...body,
            reservation_token: freshToken,
        };

        const retry = await originalFetch(input, withBody(init, retryBody));
        if (retry.ok) {
            const json = await responseJson(retry);
            const localDraft = readLocalDraft();
            if (localDraft && normalize(localDraft.reservation_token) === freshToken) {
                if (json?.uuid) {
                    localDraft.autosave_uuid = json.uuid;
                }
                if (json?.version) {
                    localDraft.autosave_version = json.version;
                }
                writeLocalDraft(localDraft);
            }
            console.warn('PREINVOICE_STALE_TOKEN_RECOVERED', {source: 'autosave'});
        }

        return retry;
    }

    async function retryReservationSyncWithFreshToken(input, init, body) {
        if (await activeServerAutosaveExists()) {
            return null;
        }

        const oldToken = normalize(body.reservation_token);
        if (!oldToken) {
            return null;
        }

        const freshToken = rotateReservationToken(oldToken);
        const retryBody = {
            ...body,
            reservation_token: freshToken,
            submission_token: freshToken,
        };

        const retry = await originalFetch(input, withBody(init, retryBody));
        if (retry.ok) {
            const json = await responseJson(retry);
            if (!json?.data?.skipped) {
                console.warn('PREINVOICE_STALE_TOKEN_RECOVERED', {source: 'reservation_sync'});
            }
        }

        return retry;
    }

    window.fetch = async function preinvoiceSafeFetch(input, init = {}) {
        const url = requestUrl(input);
        if (!url || url.origin !== window.location.origin || requestMethod(input, init) !== 'POST') {
            return originalFetch(input, init);
        }

        const response = await originalFetch(input, init);
        const body = parseJsonBody(init);
        if (!body) {
            return response;
        }

        if (url.pathname === AUTOSAVE_PATH && response.status === 409 && !normalize(body.draft_uuid)) {
            const retry = await retryAutosaveWithFreshToken(input, init, body);
            return retry || response;
        }

        if (url.pathname === RESERVATION_SYNC_PATH && response.ok && !normalize(body.preinvoice_uuid)) {
            const json = await responseJson(response);
            if (json?.data?.skipped && json?.data?.reason === 'protected_or_foreign_token') {
                const retry = await retryReservationSyncWithFreshToken(input, init, body);
                return retry || response;
            }
        }

        return response;
    };
})();
