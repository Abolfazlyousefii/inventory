<?php

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use App\Support\PageAccessCatalog;
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
        return $this->user() !== null && PageAccessCatalog::userCan($this->user(), 'page.roles');
    }

    protected function prepareForValidation(): void
    {
        $prepared = [];

        $changeRoles = $this->authorize() && $this->boolean('roles_changed');
        $changePermissions = false;

        $this->catalogVersionChanged = ! hash_equals(
            PermissionCatalog::versionHash(),
            (string) $this->input('permission_catalog_version', '')
        );

        if ($changeRoles && $this->boolean('roles_submitted') && ! $this->has('roles')) {
            $prepared['roles'] = [];
        }

        if ($this->boolean('direct_permissions_changed')) {
            $submitted = array_values(array_unique(array_filter(
                (array) $this->input('direct_permissions', []),
                'is_string'
            )));
            $this->ignoredDirectPermissions = $submitted;

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
        $canAssignRoles = $this->authorize();
        $canEditPermissions = false;
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
