<?php

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UpdateUserPermissionsRequest extends FormRequest
{
    /** @var array<int, string> */
    private array $ignoredDirectPermissions = [];

    private bool $catalogVersionChanged = false;

    public function authorize(): bool
    {
        return PermissionCatalog::userHasPermission($this->user(), 'permissions.edit')
            || PermissionCatalog::userHasPermission($this->user(), 'permissions.assign_roles');
    }

    protected function prepareForValidation(): void
    {
        $prepared = [];

        $changeRoles = PermissionCatalog::userHasPermission($this->user(), 'permissions.assign_roles')
            && $this->boolean('roles_changed');
        $changePermissions = PermissionCatalog::userHasPermission($this->user(), 'permissions.edit')
            && $this->boolean('direct_permissions_changed');

        $this->catalogVersionChanged = ! hash_equals(
            PermissionCatalog::versionHash(),
            (string) $this->input('permission_catalog_version', '')
        );

        if ($changeRoles && $this->boolean('roles_submitted') && ! $this->has('roles')) {
            $prepared['roles'] = [];
        }

        if ($changePermissions) {
            $submitted = array_values(array_unique(array_filter(
                (array) $this->input('direct_permissions', []),
                'is_string'
            )));
            $activeKeys = PermissionCatalog::activeKeys();
            $prepared['direct_permissions'] = array_values(array_intersect($submitted, $activeKeys));
            $this->ignoredDirectPermissions = array_values(array_diff($submitted, $activeKeys));

            if ($this->ignoredDirectPermissions !== []) {
                Log::warning('Stale or invalid direct permissions ignored', [
                    'actor_id' => $this->user()?->id,
                    'target_user_id' => $this->integer('user_id'),
                    'invalid_keys' => $this->ignoredDirectPermissions,
                ]);
            }
        }

        if ($prepared !== []) {
            $this->merge($prepared);
        }
    }

    public function rules(): array
    {
        $canAssignRoles = PermissionCatalog::userHasPermission($this->user(), 'permissions.assign_roles');
        $canEditPermissions = PermissionCatalog::userHasPermission($this->user(), 'permissions.edit');
        $changeRoles = $canAssignRoles && $this->boolean('roles_changed');
        $changePermissions = $canEditPermissions && $this->boolean('direct_permissions_changed');

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'permission_catalog_version' => ['nullable', 'string', 'max:128'],
            'roles_submitted' => ['sometimes', 'accepted'],
            'roles_changed' => ['required', 'boolean'],
            'roles' => [Rule::excludeIf(! $changeRoles), 'present', 'array'],
            'roles.*' => [
                Rule::excludeIf(! $changeRoles),
                'string',
                'distinct',
                'filled',
                Rule::exists('roles', 'name')->where('guard_name', PermissionCatalog::guardName()),
            ],
            'direct_permissions_submitted' => ['sometimes', 'accepted'],
            'direct_permissions_changed' => ['required', 'boolean'],
            'direct_permissions' => [Rule::excludeIf(! $changePermissions), 'present', 'array'],
            'direct_permissions.*' => [Rule::excludeIf(! $changePermissions), 'string', 'distinct', 'filled'],
        ];
    }

    /** @return array<int, string> */
    public function ignoredDirectPermissions(): array
    {
        return $this->ignoredDirectPermissions;
    }

    public function catalogVersionChanged(): bool
    {
        return $this->catalogVersionChanged;
    }

    public function messages(): array
    {
        return [
            'roles.array' => 'ساختار نقش های ارسالی معتبر نیست.',
            'roles.*.distinct' => 'نقش تکراری قابل ثبت نیست.',
            'roles.*.exists' => 'یک یا چند نقش معتبر نیست یا Guard صحیحی ندارد.',
            'direct_permissions.*.distinct' => 'دسترسی تکراری قابل ثبت نیست.',
        ];
    }
}
