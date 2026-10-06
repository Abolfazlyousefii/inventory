(() => {
    'use strict';

    const root = document.querySelector('[data-stock-threshold-editor]');
    if (!root) return;

    const dialog = document.getElementById('threshold-editor-dialog');
    const closeModal = document.getElementById('threshold-close-modal');
    const form = document.getElementById('threshold-rule-form');
    const type = document.getElementById('threshold-type');
    const target = document.getElementById('threshold-target');
    const searchField = document.getElementById('threshold-search-field');
    const search = document.getElementById('threshold-search');
    const searchStatus = document.getElementById('threshold-search-status');
    const measure = document.getElementById('threshold-measure');
    const minimum = document.getElementById('threshold-minimum');
    const title = document.getElementById('threshold-editor-title');
    const message = document.getElementById('threshold-edit-message');
    const submit = document.getElementById('threshold-submit');
    const cancelEdit = document.getElementById('threshold-cancel-edit');

    if (![dialog, closeModal, form, type, target, searchField, search, searchStatus, measure, minimum, title, message, submit, cancelEdit].every(Boolean)) return;

    const categoryOptions = target.innerHTML;
    const defaultMessage = message.textContent;
    let sequence = 0;
    let timer = null;
    let pending = null;
    let previousTrigger = null;
    let submitting = false;
    const lockedFieldNames = ['target_type', 'target_id', 'measure'];

    function stopSearch() {
        sequence += 1;
        clearTimeout(timer);
        if (pending) pending.abort();
        pending = null;
    }

    function applyType(nextType) {
        stopSearch();
        const isCategory = nextType === 'category';
        type.value = nextType;
        searchField.hidden = isCategory;
        search.disabled = isCategory;
        search.value = '';
        searchStatus.textContent = '';
        target.innerHTML = isCategory ? categoryOptions : '';
        if (!isCategory) target.add(new Option('ابتدا جست‌وجو کنید', ''));
        measure.querySelector('option[value="product"]').disabled = nextType === 'variant';
        measure.value = nextType === 'variant' ? 'variant' : 'product';
    }

    function lockScope(locked, label = '') {
        form.querySelectorAll('[data-threshold-locked-input]').forEach(input => input.remove());
        [type, target, measure].forEach(element => { element.disabled = locked; });
        if (locked) {
            lockedFieldNames.forEach(name => {
                const field = form.elements.namedItem(name);
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = field.value;
                hidden.dataset.thresholdLockedInput = '1';
                form.appendChild(hidden);
            });
            [['_threshold_editing', '1'], ['_threshold_label', label]].forEach(([name, value]) => {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = value;
                hidden.dataset.thresholdLockedInput = '1';
                form.appendChild(hidden);
            });
        }
        search.disabled = locked || type.value === 'category';
        searchField.hidden = locked || type.value === 'category';
    }

    function resetEditor() {
        lockScope(false);
        applyType('category');
        target.value = '';
        minimum.value = '';
        title.textContent = 'تنظیم آستانه جدید';
        submit.textContent = 'ذخیره آستانه';
        message.textContent = defaultMessage;
    }

    function showEditor(trigger, focusInput = type) {
        previousTrigger = trigger || document.activeElement;
        dialog.showModal();
        focusInput.focus({ preventScroll: true });
    }

    function createEditor(trigger) {
        resetEditor();
        showEditor(trigger, type);
    }

    document.querySelectorAll('[data-threshold-create]').forEach(button => {
        button.addEventListener('click', () => createEditor(button));
    });

    closeModal.addEventListener('click', () => dialog.close());
    cancelEdit.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        if (!submitting) {
            resetEditor();
            previousTrigger?.focus({ preventScroll: true });
        }
    });
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });

    type.addEventListener('change', () => {
        applyType(type.value);
        title.textContent = 'تنظیم آستانه جدید';
        message.textContent = defaultMessage;
        submit.textContent = 'ذخیره آستانه';
    });

    search.addEventListener('input', () => {
        stopSearch();
        const seq = sequence;
        target.replaceChildren(new Option('یک مورد انتخاب کنید', ''));

        const query = search.value.trim();
        if (query.length < 2) {
            searchStatus.textContent = 'حداقل دو حرف یا کد کالا وارد کنید.';
            return;
        }

        timer = setTimeout(async () => {
            const abort = new AbortController();
            pending = abort;
            searchStatus.textContent = 'در حال جست‌وجو…';

            try {
                const url = new URL(root.dataset.searchUrl, window.location.origin);
                url.searchParams.set('type', type.value);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: abort.signal,
                });

                if (!response.ok) throw new Error('search_failed');
                const result = await response.json();
                if (seq !== sequence) return;

                const items = Array.isArray(result.items) ? result.items : [];
                items.forEach(item => target.add(new Option(String(item.name), String(item.id))));
                searchStatus.textContent = items.length
                    ? items.length.toLocaleString('fa-IR') + ' نتیجه؛ مورد مناسب را از فهرست انتخاب کنید.'
                    : 'نتیجه‌ای یافت نشد.';
            } catch (error) {
                if (seq === sequence && error.name !== 'AbortError') {
                    searchStatus.textContent = 'جست‌وجو انجام نشد؛ دوباره تلاش کنید.';
                }
            } finally {
                if (pending === abort) pending = null;
            }
        }, 300);
    });

    document.querySelectorAll('[data-threshold-edit]').forEach(button => {
        button.addEventListener('click', () => {
            applyType(button.dataset.type);
            if (button.dataset.type === 'category') {
                target.value = button.dataset.targetId;
            } else {
                target.replaceChildren(new Option(button.dataset.ruleName, button.dataset.targetId, true, true));
                search.value = button.dataset.ruleName;
                searchStatus.textContent = 'مورد انتخاب‌شده آماده ویرایش است.';
            }

            measure.value = button.dataset.measure;
            minimum.value = button.dataset.minimum;
            title.textContent = 'ویرایش آستانه موجود';
            message.textContent = 'در حالت ویرایش، فقط مقدار حداقل موجودی تغییر می‌کند. برای تغییر محدوده، قانون جدید تعریف کنید.';
            submit.textContent = 'ذخیره تغییرات';
            lockScope(true, button.dataset.ruleName);
            showEditor(button, minimum);
        });
    });

    if (dialog.dataset.reopenOnError === '1') {
        const savedType = ['category', 'product', 'variant'].includes(dialog.dataset.oldType) ? dialog.dataset.oldType : 'category';
        applyType(savedType);
        if (savedType === 'category') {
            target.value = dialog.dataset.oldTargetId;
        } else if (dialog.dataset.oldTargetId) {
            const label = dialog.dataset.oldTargetLabel || 'مورد انتخاب‌شده (' + dialog.dataset.oldTargetId + ')';
            target.replaceChildren(new Option(label, dialog.dataset.oldTargetId, true, true));
            search.value = label;
            searchStatus.textContent = 'مورد انتخاب‌شده از فرم قبلی بازیابی شد.';
        }
        measure.value = savedType === 'variant' ? 'variant' : (dialog.dataset.oldMeasure === 'variant' ? 'variant' : 'product');
        minimum.value = dialog.dataset.oldMinimum;
        if (dialog.dataset.oldEditing === '1' && target.value) {
            title.textContent = 'ویرایش آستانه موجود';
            submit.textContent = 'ذخیره تغییرات';
            lockScope(true, dialog.dataset.oldTargetLabel || '');
        }
        showEditor(null, minimum);
    } else {
        resetEditor();
    }

    form.addEventListener('submit', event => {
        if (!target.value) {
            event.preventDefault();
            target.focus();
            searchStatus.textContent = type.value === 'category'
                ? 'یک دسته‌بندی انتخاب کنید.'
                : 'ابتدا کالا یا تنوع موردنظر را جست‌وجو و انتخاب کنید.';
            return;
        }
        submitting = true;
    });
})();
