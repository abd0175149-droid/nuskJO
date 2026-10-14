<?php

namespace App\Http\Requests;

use App\Services\Security\RoleAssignmentGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** تعديل مستخدم: users.update على مستخدم يحق للمعدّل إدارته؛ وكلمة المرور هنا للمدير وحده. */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor?->can('users.update')
            && RoleAssignmentGuard::canManageUser($actor, $target)
            && (!$this->filled('password') || $actor->isAdmin());
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user')->id)],
            'role_id' => 'required|exists:roles,id',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }
}
