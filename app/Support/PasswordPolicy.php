<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * سياسة كلمة المرور الواحدة (E27) للملف الشخصي وإعادة التعيين وإنشاء المستخدم وتعديله. الافتراضي سلوك اليوم
 * (8 أحرف)، و10 أحرف بحروف وأرقام قرار المالك: PASSWORD_MIN_LENGTH=10 وPASSWORD_LETTERS_AND_NUMBERS=true.
 * لا تطال كلمات المرور القائمة؛ تُطبَّق عند التغيير التالي.
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min(max(8, (int) config('security.password.min', 8)));

        return config('security.password.letters_and_numbers') ? $rule->letters()->numbers() : $rule;
    }
}
