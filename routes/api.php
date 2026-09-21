<?php

use App\Http\Controllers\BotApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| واجهة البوت — تُنادى من منصّة AiBot
|--------------------------------------------------------------------------
|
| هذه المسارات هي الجسر بين بيانات نُسك وأدوات البوت على المنصّة.
| كلّها محميّة بتوكن ثابت (`bot.token`) تحمله المنصّة في `secrets_enc`،
| ولا تدخل جلسةَ Laravel ولا تستعمل كوكي — فلا سطح CSRF أصلاً.
|
| ⚠️ لا تفتح أيّاً منها بلا توكن: `offers` تبدو عامّة، لكنّها تكشف
|    أسعارك وهوامشك لأيّ من يعرف العنوان.
*/
Route::middleware('bot.token')->prefix('bot')->group(function () {
    Route::get('offers', [BotApiController::class, 'offers']);
    Route::get('offers/{id}', [BotApiController::class, 'offer'])->whereNumber('id');

    // الهويّة تأتي من المنصّة عبر `phone` المحقون من المحادثة لا من النموذج
    Route::get('me/balance', [BotApiController::class, 'balance']);
    Route::get('me/invoices', [BotApiController::class, 'invoices']);
    Route::get('me/trips', [BotApiController::class, 'trips']);

    // أفعالٌ تكتب — لا تُنادى إلّا بعد ضغط الزبون زرّ تأكيدٍ في المنصّة
    Route::post('quotes', [BotApiController::class, 'quote']);
    Route::post('bookings', [BotApiController::class, 'booking']);
});
