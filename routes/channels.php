<?php

use Illuminate\Support\Facades\Broadcast;

/*
| القنوات الخاصة وحدها (E28): لا قناة عامة في التطبيق، ولا قنوات لكل محادثة.
| بروتوكول Pusher يفوّض الاشتراك مرة واحدة عند الانضمام، فقناة محادثة تبقى مفتوحة لمن سُحبت رؤيته.
| أحداث الصندوق تُرسل إلى قناة كل مستخدم مخوّل لحظة الحدث (InboxAudience بقواعد سياج E04)، وحمولتها معرّف ونوع فقط.
| /broadcasting/auth خلف auth وactive وحد broadcast-auth (bootstrap/app.php).
*/

// قناة خاصة لكل مستخدم: الجرس (NewNotification) وأحداث الصندوق (WaConversationChanged)
Broadcast::channel('user.{id}', function ($user, $id) {
    // التعطيل يُفحص هنا أيضاً، لا في الوسيط وحده
    return (int) $user->id === (int) $id && $user->is_active;
});

// تحديثات البيانات العامة للموظفين (DataUpdated: نوع الكيان ورقمه): لأي موظف نشط مصادَق، بعد أن كانت قناة عامة
Broadcast::channel('staff', function ($user) {
    return (bool) $user->is_active;
});
