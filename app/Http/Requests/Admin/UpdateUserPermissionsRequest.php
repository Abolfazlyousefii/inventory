<?php

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('permissions.edit') ?? false; }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'roles' => ['array'], 'roles.*' => ['string', 'distinct', 'exists:roles,name'],
            'direct_permissions' => ['array'],
            'direct_permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::activeKeys())],
        ];
    }
}
