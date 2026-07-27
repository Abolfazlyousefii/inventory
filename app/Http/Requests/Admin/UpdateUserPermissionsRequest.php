<?php

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PermissionCatalog::userHasPermission($this->user(), 'permissions.edit');
    }

    protected function prepareForValidation(): void
    {
        $prepared = [];

        if ($this->boolean('roles_submitted') && ! $this->has('roles')) {
            $prepared['roles'] = [];
        }

        if ($this->boolean('direct_permissions_submitted') && ! $this->has('direct_permissions')) {
            $prepared['direct_permissions'] = [];
        }

        if ($prepared !== []) {
            $this->merge($prepared);
        }
    }

    public function rules(): array
    {
        $canAssignRoles = PermissionCatalog::userHasPermission($this->user(), 'permissions.assign_roles');

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'roles_submitted' => ['sometimes', 'accepted'],
            'roles' => [Rule::excludeIf(! $canAssignRoles), 'present', 'array'],
            'roles.*' => [
                'string',
                'distinct',
                'filled',
                Rule::exists('roles', 'name')->where('guard_name', PermissionCatalog::guardName()),
            ],
            'direct_permissions_submitted' => ['required', 'accepted'],
            'direct_permissions' => ['present', 'array'],
            'direct_permissions.*' => ['string', 'distinct', 'filled', Rule::in(PermissionCatalog::activeKeys())],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.array' => 'ساختار نقش های ارسالی معتبر نیست.',
            'roles.*.distinct' => 'نقش تکراری قابل ثبت نیست.',
            'roles.*.exists' => 'یک یا چند نقش معتبر نیست یا Guard صحیحی ندارد.',
            'direct_permissions.*.in' => 'دسترسی ناشناخته یا قدیمی قابل انتساب نیست.',
            'direct_permissions.*.distinct' => 'دسترسی تکراری قابل ثبت نیست.',
        ];
    }
}
