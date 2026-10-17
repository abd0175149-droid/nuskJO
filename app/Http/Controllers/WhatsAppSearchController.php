<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\Search\MessageFilters;
use App\Services\WhatsApp\Search\MessageSearch;
use App\Services\WhatsApp\Search\SearchAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * البحث في نص الرسائل (E30.4): GET api/whatsapp/search، 30 في الدقيقة (throttle بمفتاح خاص لا يشارك المسارات
 * الأخرى عدّادها). محصور بالسياج داخل الخدمة قبل الحد والإزاحة، وخلف العلم message_search (404 مطفأً).
 */
class WhatsAppSearchController extends Controller
{
    public function search(Request $request, MessageSearch $search)
    {
        $user = auth()->user();
        abort_unless($user->can('whatsapp.view'), 403);
        abort_unless(MessageSearch::enabledFor($user), 404);

        $f = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'conversation_id' => 'nullable|integer|min:1',
            'sort' => 'nullable|in:recent,relevance',
            'limit' => 'nullable|integer|min:1|max:'.MessageSearch::MAX_LIMIT,
            'offset' => 'nullable|integer|min:0|max:'.MessageSearch::MAX_OFFSET,
        ] + MessageFilters::rules(), [
            'q.required' => 'اكتب نصاً للبحث',
            'q.min' => 'اكتب حرفين على الأقل',
            'q.max' => 'نص البحث أطول من 100 حرف',
            'offset.max' => 'نتائج كثيرة: ضيّق البحث بالفلاتر بدل التصفح',
        ]);

        $result = $search->search($user, $f);
        SearchAudit::record($user, $result, [...MessageFilters::present($f), ...array_keys(array_filter(Arr::only($f, ['conversation_id', 'sort'])))]);

        return response()->json(Arr::only($result, ['hits', 'total', 'total_capped', 'took_ms', 'engine', 'sort']));
    }
}
