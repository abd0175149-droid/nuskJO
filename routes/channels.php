<?php

use Illuminate\Support\Facades\Broadcast;

// قناة خاصة لكل مستخدم (إشعارات)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
