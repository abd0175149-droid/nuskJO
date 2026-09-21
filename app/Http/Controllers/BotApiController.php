<?php

namespace App\Http\Controllers;

use App\Models\WaConversation;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * واجهة البوت — تُنادى من منصّة AiBot كأدوات HTTP.
 *
 * لماذا وُجدت: بعد نقل البوت إلى المنصّة لم تعد أدواته تقرأ قاعدة نُسك
 * مباشرةً. فبقي المنطق **حيث ينتمي** — مع بيانات نُسك — وصارت المنصّة
 * تناديه عبر HTTP كأيّ عميلٍ آخر. ولا امتياز لنُسك داخل المنصّة.
 *
 * ★ والقرار الأهمّ هنا: هذا المتحكّم **لا يُعيد كتابة أيّ منطق**. ينادي
 *   `BotTools::execute` نفسه الذي كان يعمل — فالسلوك الذي اختُبر على زبائن
 *   حقيقيّين يبقى كما هو حرفيّاً: نفس الإسناد لموظّف العميل، ونفس وسم
 *   المحادثة، ونفس نصوص `note` التي تضبط النموذج.
 *   نسخُ المنطق هنا كان سيُنتج بوتين يتباعدان مع أوّل تعديل.
 *
 * ثلاث قواعد تحكم كلّ مسار:
 *  ① **الهويّة من المنصّة لا من النموذج.** `phone` تحقنه المنصّة من
 *     **المحادثة** نفسها (`{{__contact_phone}}`) لا ممّا يكتبه النموذج.
 *     فلا يطلب أحدٌ رصيد رقمٍ غير رقمه ولو أقنع النموذج بذلك.
 *  ② **لا رقم يُحسب في أيّ طبقة.** المبالغ تُعاد كما هي، و`note` تُذكّر
 *     النموذج ألّا يجمع ولا يقرّب.
 *  ③ **رقمٌ غير مربوط = لا بيانات.** الصمت الآمن أفضل من تخمينٍ خاطئ.
 */
class BotApiController extends Controller
{
    public function offers(Request $request): JsonResponse
    {
        return $this->tool('get_offers', ['category' => $request->query('category')], $request);
    }

    public function offer(Request $request, int $id): JsonResponse
    {
        return $this->tool('get_offer_details', ['offer_id' => $id], $request);
    }

    public function balance(Request $request): JsonResponse
    {
        return $this->tool('get_my_balance', [], $request);
    }

    public function invoices(Request $request): JsonResponse
    {
        return $this->tool('get_my_invoices', [], $request);
    }

    public function trips(Request $request): JsonResponse
    {
        return $this->tool('get_my_trips', [], $request);
    }

    /**
     * طلب تسعير.
     * ⚠️ المنصّة لا تنادي هذا إلّا **بعد ضغط الزبون زرّ تأكيد** — النموذج
     *    يقترح ولا ينفّذ. فهذا المسار يُنشئ سجلّاً فعليّاً ويحوّل لموظّف.
     */
    public function quote(Request $request): JsonResponse
    {
        $in = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'type' => ['required', 'string', 'max:32'],
            'route_from' => ['nullable', 'string', 'max:120'],
            'route_to' => ['nullable', 'string', 'max:120'],
            'depart_date' => ['nullable', 'string', 'max:20'],
            'return_date' => ['nullable', 'string', 'max:20'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:60'],
            'children' => ['nullable', 'integer', 'min:0', 'max:60'],
            'infants' => ['nullable', 'integer', 'min:0', 'max:20'],
            'details' => ['required', 'string', 'max:2000'],
        ]);

        return $this->tool('request_quote', $in, $request);
    }

    public function booking(Request $request): JsonResponse
    {
        $in = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'offer_id' => ['required', 'integer'],
            'details' => ['nullable', 'string', 'max:2000'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:60'],
            'children' => ['nullable', 'integer', 'min:0', 'max:60'],
            'infants' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        return $this->tool('confirm_booking', $in, $request);
    }

    /* ───────────────────────── الجسر ───────────────────────── */

    /**
     * يحلّ المحادثة من الرقم ثمّ ينادي الأداة الأصليّة.
     *
     * المحادثة هي حامل الهويّة في نُسك (`client_id` عليها)، فبلا محادثةٍ
     * لا هويّة — وهو ما يجعل الرقم غير المربوط بلا بيانات تلقائيّاً.
     */
    private function tool(string $name, array $in, Request $request): JsonResponse
    {
        $phone = (string) ($in['phone'] ?? $request->query('phone', ''));
        $conv = $this->conversationOf($phone);

        // أدواتٌ عامّة لا تحتاج هويّة: تعمل بمحادثةٍ عابرة غير محفوظة
        if (! $conv) {
            if (in_array($name, ['get_offers', 'get_offer_details'], true)) {
                $conv = new WaConversation(['phone' => PhoneNormalizer::canonical($phone) ?: '-']);
            } else {
                return response()->json([
                    'ok' => true,
                    'data' => ['linked' => false],
                    'note' => 'رقم العميل غير مربوط بحساب عندنا. لا تذكر أي مبلغ ولا فاتورة. '
                        .'اعتذر بلطف واعرض تحويله لموظف ليربط حسابه.',
                ]);
            }
        }

        $result = BotTools::execute($name, $in, $conv);

        return response()->json([
            'ok' => ! isset($result['error']),
            'data' => $result,
            // `note` تعليماتٌ للنموذج لا للزبون — والمنصّة تغلّفها كبيانات
            'note' => $result['note'] ?? null,
        ]);
    }

    /** نطبّع الرقم هنا أيضاً — لا نثق بمدخلٍ خارجيّ ولو جاء من منصّتنا. */
    private function conversationOf(string $phone): ?WaConversation
    {
        $norm = PhoneNormalizer::canonical($phone);

        return $norm ? WaConversation::where('phone', $norm)->first() : null;
    }
}
