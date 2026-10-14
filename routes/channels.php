<?php

use Illuminate\Support\Facades\Broadcast;

// قناة خاصة لكل مستخدم (إشعارات)
Broadcast::channel('user.{id}', function ($user, $id) {
    // broadcasting/auth خارج مجموعة المسارات المحمية، فالتعطيل يُفحص هنا أيضاً
    return (int) $user->id === (int) $id && $user->is_active;
});
