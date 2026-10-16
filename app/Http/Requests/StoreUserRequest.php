<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Services\Security\RoleAssignmentGuard;
use Illuminate\Foundation\Http\FormRequest;

/** إنشاء مستخدم: users.create، والدور ضمن ما يحق للمنشئ إسناده. */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (!$actor?->can('users.create')) {
            return false;
        }

        $role = Role::find($this->input('role_id'));

        return $role === null || RoleAssignmentGuard::canAssign($actor, $role);   // دور غير موجود يرفضه rules()
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', \App\Support\PasswordPolicy::rule()],
            'role_id' => 'required|exists:roles,id',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ];
    }
}
