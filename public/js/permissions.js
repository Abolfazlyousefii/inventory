document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('permissionForm');

    if (!form) {
        return;
    }

    const changeCount = document.getElementById('changeCount');
    const dependencyCount = document.getElementById('dependencyCount');
    const saveButton = document.getElementById('saveButton');
    const search = document.getElementById('permissionSearch');
    const moduleButtons = [...document.querySelectorAll('.permission-module')];
    const permissionRows = [...document.querySelectorAll('.permission-row')];
    let submitting = false;

    const serialize = () => {
        const entries = [...new FormData(form).entries()]
            .map(([key, value]) => `${key}=${String(value)}`)
            .sort();

        return JSON.stringify(entries);
    };

    const initialState = serialize();

    const updateDirtyState = () => {
        const dirty = serialize() !== initialState;

        if (changeCount) {
            changeCount.textContent = dirty ? 'تغییر ذخیره نشده دارید' : 'بدون تغییر ذخیره نشده';
        }
        if (saveButton && !submitting) {
            saveButton.disabled = !dirty;
        }

        return dirty;
    };

    const normalizeDependencies = (checkbox) => {
        if (!checkbox.classList.contains('permission-check') || !checkbox.checked) {
            return 0;
        }

        let dependencies = [];
        try {
            const parsed = JSON.parse(checkbox.dataset.dependencies || '[]');
            dependencies = Array.isArray(parsed) ? parsed : [];
        } catch {
            dependencies = [];
        }

        let added = 0;
        dependencies.forEach((key) => {
            const dependency = [...form.querySelectorAll('.permission-check')]
                .find((input) => input.value === key);
            if (dependency && !dependency.checked && !dependency.disabled) {
                dependency.checked = true;
                added += 1;
                added += normalizeDependencies(dependency);
            }
        });

        return added;
    };

    form.querySelectorAll('.change-input').forEach((input) => {
        input.addEventListener('change', () => {
            const added = normalizeDependencies(input);
            if (dependencyCount) {
                dependencyCount.textContent = added > 0
                    ? `${new Intl.NumberFormat('fa-IR').format(added)} وابستگی به صورت خودکار افزوده شد.`
                    : '';
            }
            updateDirtyState();
        });
    });

    const filterRows = () => {
        const activeModule = document.querySelector('.permission-module.btn-primary')?.dataset.module || 'all';
        const query = (search?.value || '').trim().toLocaleLowerCase('fa');

        permissionRows.forEach((row) => {
            const moduleMismatch = activeModule !== 'all' && row.dataset.module !== activeModule;
            const searchMismatch = query !== '' && !(row.dataset.search || '').toLocaleLowerCase('fa').includes(query);
            row.hidden = moduleMismatch || searchMismatch;
        });
    };

    moduleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            moduleButtons.forEach((item) => {
                item.classList.remove('btn-primary');
                item.classList.add('btn-outline-secondary');
            });
            button.classList.remove('btn-outline-secondary');
            button.classList.add('btn-primary');
            filterRows();
        });
    });

    search?.addEventListener('input', filterRows);

    window.addEventListener('beforeunload', (event) => {
        if (!submitting && updateDirtyState()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'در حال ذخیره…';
        }
    });

    updateDirtyState();
});
