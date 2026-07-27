@extends('layouts.app')

@section('content')
<style>
  .permission-shell {
    --pm: #4f46e5
  }

  .permission-modules {
    position: sticky;
    top: 1rem;
    max-height: 72vh;
    overflow: auto
  }

  .permission-module {
    cursor: pointer
  }

  .permission-row[hidden] {
    display: none !important
  }

  .risk-critical {
    border-right: 4px solid #dc3545
  }

  .permission-savebar {
    position: sticky;
    bottom: 12px;
    z-index: 1020;
    background: #fff;
    border: 1px solid #dee2e6;
    box-shadow: 0 -6px 24px #0f172a20
  }

  .role-card {
    height: 100%;
    cursor: pointer
  }

  .role-card:has(input:checked) {
    border-color: var(--pm) !important;
    background: #eef2ff
  }

  .technical-key {
    direction: ltr;
    text-align: left
  }

  @media(max-width:991.98px) {
    .permission-modules {
      position: static;
      display: flex;
      overflow-x: auto;
      max-height: none
    }

    .permission-module {
      min-width: 150px
    }

    .permission-savebar .btn {
      width: 100%
    }
  }
</style>

<div class="permission-shell pb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h4 class="mb-1">مدیریت نقش‌ها و دسترسی کاربران</h4>
      <p class="text-muted mb-0">دسترسی مؤثر، مجموع دسترسی نقش‌ها و دسترسی‌های مستقیم افزایشی است.</p>
    </div>
    <span class="badge text-bg-light border">دسترسی‌های قدیمی در این صفحه مخفی‌اند</span>
  </div>

  @if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
  @endif

  @if($errors->any())
  <div class="alert alert-danger">
    <ul class="mb-0">
      @foreach($errors->all() as $error)
      <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
  @endif

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-lg-9">
          <label for="userPicker" class="form-label fw-bold">انتخاب کاربر</label>
          <select id="userPicker" name="user_id" class="form-select" onchange="this.form.submit()">
            @foreach($users as $u)
            <option value="{{ $u->id }}" @selected($selectedUser?->is($u))>
              {{ $u->name }} — {{ $u->phone ?: $u->email }}
              @if($u->personnel_code)
              — {{ $u->personnel_code }}
              @endif
              — {{ $u->roles->pluck('name')->join('، ') ?: 'بدون نقش' }}
              — {{ $u->is_active ? 'فعال' : 'غیرفعال' }}
            </option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-3 d-grid">
          <button class="btn btn-outline-primary">بارگذاری اطلاعات</button>
        </div>
      </form>
    </div>
  </div>

  @if($selectedUser)
  @php
  $directCount = collect($effective)->where('source', 'direct')->count()
  + collect($effective)->where('source', 'both')->count();
  $effectiveCount = collect($effective)->where('granted', true)->count();
  $canAssignRoles = auth()->user()->can('permissions.assign_roles');
  $canEditPermissions = auth()->user()->can('permissions.edit');
  $roleLabelsMap = (array) ($roleLabels ?? []);
  $roleAliasesMap = (array) ($roleAliases ?? []);
  @endphp

  <div class="row g-2 mb-3">
    @foreach([
    ['نام کاربر', $selectedUser->name],
    ['شماره موبایل', $selectedUser->phone ?: '—'],
    ['تعداد نقش', $selectedUser->roles->count()],
    ['دسترسی مؤثر', $effectiveCount],
    ['دسترسی مستقیم', $directCount],
    ['وضعیت', $selectedUser->is_active ? 'فعال' : 'غیرفعال']
    ] as [$label, $value])
    <div class="col-6 col-lg-2">
      <div class="card h-100">
        <div class="card-body p-3">
          <small class="text-muted">{{ $label }}</small>
          <div class="fw-bold text-truncate">{{ $value }}</div>
        </div>
      </div>
    </div>
    @endforeach
  </div>

  <form id="permissionForm" method="POST" action="{{ route('admin.permissions.update', $selectedUser) }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="user_id" value="{{ $selectedUser->id }}">

    <div class="card mb-3">
      <div class="card-header fw-bold">نقش‌های کاربر</div>
      <div class="card-body">
        <div class="row g-2">
          @foreach($roles as $role)
          @php
          /*
          * نکته مهم:
          * first() مقدار آرایه aliasها را برمی‌گرداند و باعث TypeError می‌شود.
          * search() کلید نقش استاندارد را برمی‌گرداند.
          */
          $standardRole = collect($roleAliasesMap)->search(
          function ($aliases, $standardKey) use ($role) {
          return $role->name === $standardKey
          || in_array($role->name, (array) $aliases, true);
          }
          );

          if ($standardRole === false) {
          $standardRole = null;
          }

          $label = $roleLabelsMap[$role->name]
          ?? (
          $standardRole !== null
          ? ($roleLabelsMap[$standardRole] ?? null)
          : null
          )
          ?? $role->name;

          $isLegacyRole = !array_key_exists($role->name, $roleLabelsMap)
          && $standardRole === null;

          $roleIsSelected = $selectedUser->roles->contains('name', $role->name);
          $permissionsCount = $role->permissions_count ?? $role->permissions->count();
          @endphp

          <div class="col-md-4 col-xl-3">
            <label class="role-card border rounded p-3 d-block">
              <div class="d-flex gap-2">
                <input
                  class="form-check-input change-input"
                  type="checkbox"
                  name="roles[]"
                  value="{{ $role->name }}"
                  @checked($roleIsSelected)
                  @disabled(!$canAssignRoles)>

                @if(!$canAssignRoles && $roleIsSelected)
                <input type="hidden" name="roles[]" value="{{ $role->name }}">
                @endif

                <div>
                  <strong>{{ $label }}</strong>
                  <div>
                    <code>{{ $role->name }}</code>

                    @if($isLegacyRole)
                    <span class="badge text-bg-secondary">قدیمی</span>
                    @elseif($standardRole !== null && $standardRole !== $role->name)
                    <span class="badge text-bg-light border">سازگار با {{ $standardRole }}</span>
                    @endif
                  </div>
                  <small class="text-muted">{{ $permissionsCount }} دسترسی</small>
                </div>
              </div>
            </label>
          </div>
          @endforeach
        </div>

        @if(!$canAssignRoles)
        <div class="alert alert-light border mt-3 mb-0">
          شما مجوز تغییر نقش‌های کاربر را ندارید؛ نقش‌های فعلی هنگام ذخیره حفظ می‌شوند.
        </div>
        @endif
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap gap-3">
        <strong>راهنمای منبع:</strong>
        <span><span class="badge text-bg-primary">از نقش</span> قابل حذف مستقیم نیست</span>
        <span><span class="badge text-bg-success">مستقیم</span> قابل ویرایش</span>
        <span><span class="badge text-bg-info">نقش + مستقیم</span></span>
      </div>
    </div>

    <div class="row g-3">
      <aside class="col-lg-3">
        <div class="permission-modules gap-2" id="moduleList">
          <button type="button" class="permission-module btn btn-primary text-start" data-module="all">
            همه ماژول‌ها
            <span class="badge text-bg-light">{{ collect($modules)->flatten(1)->count() }}</span>
          </button>

          @foreach($modules as $module => $items)
          @php
          $moduleItems = collect($items);
          $moduleLabel = data_get($moduleItems->first(), 'module_label', $module);
          $grantedCount = $moduleItems->filter(fn ($item) => (bool) data_get($item, 'granted', false))->count();
          @endphp

          <button type="button" class="permission-module btn btn-outline-secondary text-start" data-module="{{ $module }}">
            {{ $moduleLabel }}
            <span class="badge text-bg-light">{{ $grantedCount }}/{{ $moduleItems->count() }}</span>
          </button>
          @endforeach
        </div>
      </aside>

      <section class="col-lg-9">
        <div class="card">
          <div class="card-header">
            <label class="visually-hidden" for="permissionSearch">جستجوی دسترسی</label>
            <input id="permissionSearch" class="form-control" placeholder="جستجو در عنوان فارسی، کلید، عملیات یا ماژول…">
          </div>

          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>دسترسی</th>
                    <th>وضعیت</th>
                    <th>منبع</th>
                    <th>وابستگی‌ها</th>
                    <th>حساسیت</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($modules as $module => $items)
                  @foreach(collect($items) as $item)
                  @php
                  $sourceKey = data_get($item, 'source', 'none');
                  $sourceMap = [
                  'role' => ['primary', 'از نقش'],
                  'direct' => ['success', 'مستقیم'],
                  'both' => ['info', 'نقش + مستقیم'],
                  'none' => ['secondary', 'فاقد دسترسی'],
                  ];
                  $source = $sourceMap[$sourceKey] ?? $sourceMap['none'];

                  $riskKey = data_get($item, 'risk', 'normal');
                  $riskMap = [
                  'normal' => ['secondary', 'عادی'],
                  'sensitive' => ['warning', 'حساس'],
                  'critical' => ['danger', 'بسیار حساس'],
                  ];
                  $risk = $riskMap[$riskKey] ?? $riskMap['normal'];

                  $dependsOn = array_values((array) data_get($item, 'depends_on', []));
                  $isRoleGranted = in_array($sourceKey, ['role', 'both'], true);
                  $isDirectGranted = in_array($sourceKey, ['direct', 'both'], true);
                  @endphp

                  <tr
                    class="permission-row {{ $riskKey === 'critical' ? 'risk-critical' : '' }}"
                    data-module="{{ $module }}"
                    data-search="{{ data_get($item, 'label') . ' ' . data_get($item, 'key') . ' ' . data_get($item, 'action') . ' ' . data_get($item, 'module_label') }}">
                    <td>
                      <strong>{{ data_get($item, 'label') }}</strong>
                      <small class="technical-key d-block text-muted">{{ data_get($item, 'key') }}</small>
                    </td>

                    <td>
                      <input
                        class="form-check-input permission-check change-input"
                        aria-label="{{ data_get($item, 'label') }}"
                        type="checkbox"
                        name="direct_permissions[]"
                        value="{{ data_get($item, 'key') }}"
                        data-dependencies='@json($dependsOn)'
                        @checked($isDirectGranted)
                        @disabled(!$canEditPermissions || $sourceKey==='role' )>

                      @if(!$canEditPermissions && $isDirectGranted)
                      <input type="hidden" name="direct_permissions[]" value="{{ data_get($item, 'key') }}">
                      @endif

                    </td>

                    <td>
                      <span class="badge text-bg-{{ $source[0] }}">{{ $source[1] }}</span>
                    </td>

                    <td>
                      <small>{{ collect($dependsOn)->join('، ') ?: '—' }}</small>
                    </td>

                    <td>
                      <span class="badge text-bg-{{ $risk[0] }}">{{ $risk[1] }}</span>
                    </td>
                  </tr>
                  @endforeach
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </div>

    @if($canEditPermissions || $canAssignRoles)
    <div class="permission-savebar rounded p-3 mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <strong id="changeCount">۰ تغییر ذخیره‌نشده</strong>
        <small id="dependencyCount" class="d-block text-muted"></small>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a id="cancelPermissionChanges" class="btn btn-outline-secondary" href="{{ route('admin.permissions.index', ['user_id' => $selectedUser->id]) }}">انصراف</a>
        <button id="saveButton" class="btn btn-primary" type="submit">ذخیره دسترسی‌ها</button>
      </div>
    </div>
    @endif
  </form>
  @endif
</div>

<script>
  (() => {
    const form = document.getElementById('permissionForm');
    if (!form) return;

    let dirty = false;
    let automatic = 0;

    const changeInputs = Array.from(document.querySelectorAll('.change-input'));
    const count = document.getElementById('changeCount');
    const dep = document.getElementById('dependencyCount');
    const search = document.getElementById('permissionSearch');
    const cancelLink = document.getElementById('cancelPermissionChanges');
    const saveButton = document.getElementById('saveButton');

    function changedCount() {
      return changeInputs.filter(input => input.checked !== input.defaultChecked).length;
    }

    function refreshChanges() {
      const changes = changedCount();
      dirty = changes > 0;

      if (count) {
        count.textContent = changes ?
          `${changes} تغییر ذخیره‌نشده` :
          '۰ تغییر ذخیره‌نشده';
      }

      if (dep) {
        dep.textContent = automatic ?
          `${automatic} وابستگی به‌صورت خودکار افزوده شد.` :
          '';
      }
    }

    function findPermissionCheckbox(key) {
      return Array.from(form.querySelectorAll('.permission-check'))
        .find(checkbox => checkbox.value === key) || null;
    }

    document.querySelectorAll('.change-input').forEach(input => {
      input.addEventListener('change', event => {
        const target = event.target;

        if (target.classList.contains('permission-check') && target.checked) {
          let dependencies = [];

          try {
            dependencies = JSON.parse(target.dataset.dependencies || '[]');
          } catch (error) {
            dependencies = [];
          }

          dependencies.forEach(key => {
            const dependency = findPermissionCheckbox(key);

            if (dependency && !dependency.checked && !dependency.disabled) {
              dependency.checked = true;
              automatic++;
            }
          });
        }

        refreshChanges();
      });
    });

    document.querySelectorAll('.permission-module').forEach(button => {
      button.addEventListener('click', () => {
        document.querySelectorAll('.permission-module').forEach(item => {
          item.classList.remove('btn-primary');
          item.classList.add('btn-outline-secondary');
        });

        button.classList.remove('btn-outline-secondary');
        button.classList.add('btn-primary');

        filterRows();
      });
    });

    function filterRows() {
      const activeModule = document.querySelector('.permission-module.btn-primary')?.dataset.module || 'all';
      const query = (search?.value || '').trim().toLowerCase();

      document.querySelectorAll('.permission-row').forEach(row => {
        const moduleMatches = activeModule === 'all' || row.dataset.module === activeModule;
        const searchMatches = !query || (row.dataset.search || '').toLowerCase().includes(query);
        row.hidden = !moduleMatches || !searchMatches;
      });
    }

    search?.addEventListener('input', filterRows);

    cancelLink?.addEventListener('click', () => {
      dirty = false;
    });

    window.addEventListener('beforeunload', event => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });

    form.addEventListener('submit', () => {
      dirty = false;

      if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = 'در حال ذخیره…';
      }
    });

    refreshChanges();
    filterRows();
  })();
</script>
@endsection