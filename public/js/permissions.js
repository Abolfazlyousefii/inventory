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
    const permissionSections = [...document.querySelectorAll('.permission-module-section')];
    const permissionRows = [...document.querySelectorAll('.permission-row')];
    const roleInputs = [...form.querySelectorAll('.role-check')];
    const permissionInputs = [...form.querySelectorAll('.permission-check')];
    const rolesChanged = document.getElementById('rolesChanged');
    const directPermissionsChanged = document.getElementById('directPermissionsChanged');
    let submitting = false;

    const serializeRoles = () => roleInputs.filter((input) => input.checked).map((input) => input.value).sort();
    const serializeDirectPermissions = () => permissionInputs.filter((input) => input.checked).map((input) => input.value).sort();
    const initialRoles = JSON.stringify(serializeRoles());
    const initialDirectPermissions = JSON.stringify(serializeDirectPermissions());

    const refreshChangedFlags = () => {
        const rolesDirty = JSON.stringify(serializeRoles()) !== initialRoles;
        const permissionsDirty = JSON.stringify(serializeDirectPermissions()) !== initialDirectPermissions;

        if (rolesChanged) rolesChanged.value = rolesDirty ? '1' : '0';
        if (directPermissionsChanged) directPermissionsChanged.value = permissionsDirty ? '1' : '0';

        const dirty = rolesDirty || permissionsDirty;

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
            const dependency = permissionInputs.find((input) => input.value === key);

            if (dependency && !dependency.checked && !dependency.disabled) {
                dependency.checked = true;
                added += 1;
                added += normalizeDependencies(dependency);
            }
        });

        return added;
    };

    permissionInputs.forEach((input) => {
        input.addEventListener('change', () => {
            const added = normalizeDependencies(input);

            if (dependencyCount) {
                dependencyCount.textContent = added > 0
                    ? `${new Intl.NumberFormat('fa-IR').format(added)} وابستگی به صورت خودکار افزوده شد.`
                    : '';
            }

            refreshChangedFlags();
        });
    });

    roleInputs.forEach((input) => input.addEventListener('change', refreshChangedFlags));

    const filterRows = () => {
        const activeModule = document.querySelector('.permission-module.btn-primary')?.dataset.module || 'all';
        const query = (search?.value || '').trim().toLocaleLowerCase('fa');

        permissionSections.forEach((section) => {
            const moduleMismatch = activeModule !== 'all' && section.dataset.module !== activeModule;
            const visibleRows = [...section.querySelectorAll('.permission-row')].filter((row) => {
                const searchMismatch = query !== ''
                    && !(row.dataset.search || '').toLocaleLowerCase('fa').includes(query);

                row.hidden = moduleMismatch || searchMismatch;
                return !row.hidden;
            });

            section.hidden = moduleMismatch || visibleRows.length === 0;
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
        if (!submitting && refreshChangedFlags()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    form.addEventListener('submit', (event) => {
        if (!refreshChangedFlags() || submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;

        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'در حال ذخیره…';
        }
    });

    refreshChangedFlags();
    filterRows();
});
