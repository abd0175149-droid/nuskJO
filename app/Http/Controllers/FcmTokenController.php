<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    /**
     * Store or update an FCM token for the authenticated user.
     */
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'device_type' => 'nullable|in:android,ios,web,windows,macos',
        ]);

        $token = $request->user()->fcmTokens()->updateOrCreate(
            ['token' => $request->token],
            [
                'device_type' => $request->device_type ?? 'web',
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'FCM token registered successfully.',
            'data' => $token
        ]);
    }

    /**
     * Remove an FCM token.
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $request->user()->fcmTokens()->where('token', $request->token)->delete();

        return response()->json([
            'message' => 'FCM token removed successfully.'
        ]);
    }
}
