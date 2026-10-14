<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * انتقال حالة مستند لم يحدث لأن حالته تغيّرت بين قراءتها وكتابتها (نقرة مزدوجة أو مستخدمان معاً).
 * يُرمى داخل المعاملة قبل أي قيد أو حركة ذمة، فلا يتكرر شيء.
 */
class ApprovalConflict extends RuntimeException
{
    public const MESSAGE = 'اعتُمد هذا المستند أو رُفض أو فُتح للتعديل للتو من مستخدم آخر. حدّث الصفحة.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return back()->with('error', $this->getMessage());
    }
}
