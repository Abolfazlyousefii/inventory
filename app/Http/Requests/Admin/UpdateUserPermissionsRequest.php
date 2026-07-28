<?php

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UpdateUserPermissionsRequest extends FormRequest
{
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

        if ($changeRoles && $this->boolean('roles_submitted') && ! $this->has('roles')) {
            $prepared['roles'] = [];
        }

        if ($changePermissions && $this->boolean('direct_permissions_submitted') && ! $this->has('direct_permissions')) {
            $prepared['direct_permissions'] = [];
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

    public function after(): array
    {
        return [function ($validator): void {
            $changePermissions = PermissionCatalog::userHasPermission($this->user(), 'permissions.edit')
                && $this->boolean('direct_permissions_changed');
            if (! $changePermissions || ! is_array($this->input('direct_permissions'))) {
                return;
            }

            $submitted = array_values(array_filter($this->input('direct_permissions', []), 'is_string'));
            $invalid = array_values(array_diff(array_unique($submitted), PermissionCatalog::activeKeys()));
            if ($invalid === []) {
                return;
            }

            Log::warning('Invalid direct permissions submitted', [
                'actor_id' => $this->user()?->id,
                'target_user_id' => $this->integer('user_id'),
                'invalid_keys' => $invalid,
            ]);
            $validator->errors()->add(
                'direct_permissions',
                'یک یا چند دسترسی ارسال‌شده در نسخه فعلی معتبر نیستند. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'
            );
        }];
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
