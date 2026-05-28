<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    /**
     * جلب إشعارات المستخدم الحالي
     */
    public function index(Request $request)
    {
        try {
            $notifications = Notification::where('user_id', auth()->id())
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(function ($n) {
                    // تحويل آمن لـ created_at
                    $createdAt = $n->created_at;
                    if (is_string($createdAt)) {
                        try { $createdAt = Carbon::parse($createdAt); } catch (\Exception $e) { $createdAt = null; }
                    }

                    return [
                        'id' => $n->id,
                        'title' => $n->title ?? '',
                        'body' => $n->body ?? '',
                        'type' => $n->type ?? 'info',
                        'icon' => $n->icon ?? 'ℹ️',
                        'action_url' => $n->action_url,
                        'is_read' => (bool) $n->is_read,
                        'time_ago' => $createdAt ? $createdAt->diffForHumans() : '',
                        'created_at' => $createdAt ? $createdAt->toISOString() : '',
                    ];
                });

            return response()->json([
                'notifications' => $notifications,
                'unread_count' => Notification::where('user_id', auth()->id())->where('is_read', false)->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('NotificationController@index: ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
            return response()->json([
                'notifications' => [],
                'unread_count' => 0,
                'error' => $e->getMessage(),
            ], 200); // إرجاع 200 مع بيانات فاضية بدل 500
        }
    }

    /**
     * تعليم إشعار كمقروء
     */
    public function markAsRead(Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }

        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * تعليم الكل كمقروء
     */
    public function markAllAsRead()
    {
        Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
