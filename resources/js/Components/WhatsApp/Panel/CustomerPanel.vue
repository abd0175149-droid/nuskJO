<template>
  <!-- اللوحة الجانبية للمحادثة (C128): كل إجراءات المحادثة وتنظيمها وملاحظاتها، ورحلات العميل وفنادقه وفواتيره وطلباته.
       الأقسام تُطوى وتُفتح وتُحفظ حالتها للمتصفح؛ البيانات من مسار panel وكل تفصيل بصلاحيته في الخادم -->
  <div class="flex flex-col min-h-0 h-full bg-white dark:bg-gray-900" data-test="customer-panel">
    <div class="shrink-0 flex items-center gap-1 px-2 h-12 border-b border-gray-200 dark:border-gray-700">
      <button type="button" @click="$emit('close')" :aria-label="drawer ? 'رجوع إلى المحادثة' : 'طي اللوحة'" :title="drawer ? 'رجوع إلى المحادثة' : 'طي اللوحة'"
              class="min-w-11 min-h-11 px-2 rounded-lg text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800" data-test="panel-close">
        {{ drawer ? '→ المحادثة' : '⇥' }}
      </button>
      <h2 ref="heading" tabindex="-1" class="flex-1 min-w-0 truncate text-sm font-bold text-gray-800 dark:text-gray-100 outline-none">لوحة العميل</h2>
      <button type="button" @click="load" :disabled="loading" aria-label="تحديث بيانات اللوحة" title="تحديث"
              class="min-w-11 min-h-11 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40">
        <span aria-hidden="true" class="inline-block" :class="loading ? 'animate-spin' : ''">↻</span>
      </button>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
      <!-- الهوية والحالة -->
      <div class="px-4 pt-4 pb-3 border-b border-gray-100 dark:border-gray-800 space-y-3">
        <div class="flex items-center gap-3">
          <span aria-hidden="true" class="shrink-0 w-12 h-12 rounded-full grid place-items-center text-sm font-bold text-white" :class="colorFor(thread.id)">{{ initials(thread.name) }}</span>
          <div class="min-w-0">
            <p class="font-bold text-[15px] leading-tight text-gray-900 dark:text-white truncate"><bdi dir="auto" class="[unicode-bidi:plaintext]">{{ thread.name }}</bdi></p>
            <p v-if="thread.phone" class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
              <bdi dir="ltr" class="font-mono">{{ formatPhone(thread.phone) }}</bdi>
              <button type="button" @click="copyPhone" class="min-h-7 px-1.5 rounded text-[10px] font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800" data-test="copy-phone">
                {{ copied ? '✓ نُسخ' : 'نسخ' }}
              </button>
            </p>
            <p v-else class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">📵 رقم غير ظاهر<template v-if="thread.username"> · <bdi dir="ltr" class="font-mono">@{{ thread.username }}</bdi></template></p>
          </div>
        </div>

        <!-- العملاء المطابقون -->
        <ul v-if="data?.clients?.length" class="space-y-1" aria-label="العميل في النظام">
          <li v-for="c in data.clients" :key="c.code || c.name" class="flex items-center gap-1.5 text-xs">
            <span aria-hidden="true">👤</span>
            <a v-if="c.url" :href="c.url" class="font-bold text-gray-800 dark:text-gray-100 hover:underline"><bdi dir="auto">{{ c.name }}</bdi></a>
            <bdi v-else dir="auto" class="font-bold text-gray-800 dark:text-gray-100">{{ c.name }}</bdi>
            <span v-if="c.code" class="font-mono text-[10px] text-gray-500"><bdi dir="ltr">{{ c.code }}</bdi></span>
            <span class="ms-auto px-1.5 py-0.5 rounded text-[10px]" :class="c.source === 'linked'
                    ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ c.source === 'linked' ? '🔗 مربوط' : 'مطابق بالرقم' }}</span>
          </li>
        </ul>
        <p v-if="data?.identity?.hint" class="text-[11px] text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 rounded-lg px-2.5 py-1.5" data-test="panel-hint">{{ data.identity.hint }}</p>

        <!-- شرائح الحالة -->
        <div class="flex flex-wrap gap-1">
          <span class="wp-chip" :class="thread.bot_enabled ? 'wp-chip-green' : 'wp-chip-gray'">{{ thread.bot_enabled ? '🤖 البوت يرد' : '⏸️ البوت موقوف' }}</span>
          <span class="wp-chip" :class="thread.window_open ? 'wp-chip-sky' : 'wp-chip-gray'" :title="thread.window_open ? 'نافذة الرد المجاني من ميتا' : 'لا رد بعد 24 ساعة من آخر رسالة للعميل (لا قوالب لدينا)'">
            {{ thread.window_open ? `🟢 النافذة: ${windowText}` : '🔒 النافذة مغلقة' }}
          </span>
          <span v-if="thread.assignee" class="wp-chip wp-chip-gray">👤 <bdi dir="auto">{{ thread.assignee }}</bdi></span>
          <span v-if="thread.closed" class="wp-chip wp-chip-gray">🗂️ مغلقة</span>
          <span v-if="thread.snoozed_until" class="wp-chip wp-chip-gray">💤 حتى <bdi dir="ltr">{{ hhmm(thread.snoozed_until) }}</bdi></span>
          <span v-if="thread.pinned" class="wp-chip wp-chip-gray">📍 مثبتة</span>
          <span v-if="thread.blocked" class="wp-chip wp-chip-red">⛔ محظور</span>
          <span v-if="data?.balance" class="wp-chip" :class="data.balance.direction === 'عليه' ? 'wp-chip-red' : 'wp-chip-green'" data-test="panel-balance">
            💳 {{ data.balance.direction ? `${data.balance.direction} ` : '' }}<bdi dir="ltr" class="tabular-nums">{{ jod(data.balance.amount) }}</bdi> د.أ
          </span>
        </div>

        <!-- الرحلة القادمة مختصرة: النقر يفتح قسم الرحلات -->
        <button v-if="nextTrip" type="button" @click="focusTrips" data-test="next-trip-strip"
                class="w-full text-start rounded-xl px-3 py-2 border border-gold-300 dark:border-gold-700 bg-gold-50 dark:bg-gold-900/15 hover:bg-gold-100 dark:hover:bg-gold-900/25">
          <span class="block text-[11px] font-bold text-gold-800 dark:text-gold-300">{{ badgeText }}</span>
          <span class="block text-xs text-gray-800 dark:text-gray-100 truncate"><bdi dir="auto">{{ nextTrip.offer?.title || 'رحلة بفاتورة يدوية' }}</bdi> · {{ day(nextTrip.trip_date) }}</span>
        </button>

        <p v-if="error" class="text-xs text-red-600 dark:text-red-400" role="alert">❌ {{ error }}</p>
      </div>

      <!-- ⚡ الإجراءات -->
      <PanelSection id="actions" icon="⚡" title="الإجراءات" default-open>
        <div class="grid grid-cols-2 gap-1.5">
          <button v-if="canToggleBot" type="button" class="wp-act" :class="thread.bot_enabled ? '' : 'wp-act-green'" @click="$emit('toggleBot')">
            {{ thread.bot_enabled ? '⏸️ إيقاف البوت' : '▶️ تشغيل البوت' }}
          </button>
          <template v-if="followup">
            <template v-if="canReply">
              <button v-if="!thread.closed" type="button" class="wp-act" @click="act('close')">🗂️ إغلاق المحادثة</button>
              <button v-else type="button" class="wp-act" @click="act('reopen')">📂 إعادة الفتح</button>
            </template>
            <button type="button" class="wp-act" @click="act(thread.pinned ? 'unpin' : 'pin')">{{ thread.pinned ? '📍 إلغاء التثبيت' : '📍 تثبيت أعلى القائمة' }}</button>
            <button type="button" class="wp-act" @click="act('markUnread')" title="علامة داخلية لا يراها العميل">📌 غير مقروءة</button>
          </template>
          <template v-if="canLinkClient && !thread.is_admin">
            <button type="button" class="wp-act wp-act-blue" @click="$emit('link')">{{ thread.client_id ? '🔗 تغيير الربط' : '🔗 ربط بعميل' }}</button>
            <button v-if="thread.client_id" type="button" class="wp-act" @click="$emit('unlink')">✂️ فك الربط</button>
          </template>
          <button v-if="canExport" type="button" class="wp-act" @click="$emit('export')" data-test="panel-export">⬇️ تصدير المحادثة</button>
          <button v-if="blockable" type="button" class="wp-act wp-act-red" @click="$emit('block')" :disabled="!thread.window_open" data-test="panel-block"
                  :title="thread.window_open ? 'حظر هذا الرقم عند واتساب' : 'ميتا لا تسمح بالحظر إلا خلال 24 ساعة من آخر رسالة للعميل'">⛔ حظر الرقم</button>
          <button v-if="canReprocess && thread.stuck" type="button" class="wp-act wp-act-purple" @click="act('reprocess')">🔁 إعادة معالجة الرد العالق</button>
        </div>

        <!-- التأجيل -->
        <div v-if="followup && canReply" class="mt-3">
          <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">💤 تأجيل حتى تعود للقائمة</p>
          <div v-if="snooze.length" class="flex flex-wrap gap-1">
            <button v-for="o in snooze" :key="o.key" type="button" :disabled="o.disabled" :title="o.hint" @click="act('snooze', { minutes: o.minutes })"
                    class="min-h-8 px-2 rounded-lg text-[11px] text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-40">{{ o.label }}</button>
            <form class="flex items-center gap-1 w-full mt-1" @submit.prevent="custom && act('snooze', { until: new Date(custom).toISOString() })">
              <label :for="customId" class="sr-only">وقت مخصص</label>
              <input :id="customId" v-model="custom" type="datetime-local" :min="bounds?.min" :max="bounds?.max" dir="ltr"
                     class="flex-1 min-w-0 px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs"/>
              <button type="submit" :disabled="!custom" class="min-h-8 px-2 rounded-lg text-[11px] font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-40">تأجيل</button>
            </form>
          </div>
          <p v-else class="text-[11px] text-gray-400">لا تأجيل بعد إغلاق النافذة — لا قوالب لدينا</p>
          <button v-if="thread.snoozed_until" type="button" class="mt-1 min-h-8 px-2 rounded-lg text-[11px] font-bold text-gold-700 dark:text-gold-400" @click="act('unsnooze')">⏰ إلغاء التأجيل</button>
        </div>
      </PanelSection>

      <!-- 🏷️ التنظيم -->
      <PanelSection id="organize" icon="🏷️" title="التنظيم" :summary="organizeSummary">
        <div class="space-y-3">
          <div>
            <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">المواضيع (تحدد من يرى المحادثة)</p>
            <div class="flex flex-wrap items-center gap-1">
              <span v-for="tp in (thread.topics || [])" :key="tp.key" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px]"
                    :class="tp.source === 'manual' ? 'bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-300 ring-1 ring-gold-300 dark:ring-gold-700' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">
                {{ tp.label }}
                <button type="button" @click="$emit('setTopic', tp.key, false)" :aria-label="`إزالة وسم ${tp.label}`" class="leading-none hover:text-red-600">✕</button>
              </span>
              <span v-if="!(thread.topics || []).length" class="text-[11px] text-gray-400">بلا وسوم</span>
            </div>
            <div v-if="addable.length" class="flex items-center gap-1 mt-1.5">
              <select v-model="newTopic" aria-label="اختيار موضوع للإضافة" class="flex-1 min-w-0 px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
                <option value="">— اختر موضوعاً</option>
                <option v-for="tp in addable" :key="tp.key" :value="tp.key">{{ tp.label }}</option>
              </select>
              <button type="button" :disabled="!newTopic" @click="addTopic" class="min-h-8 px-2 rounded-lg text-[11px] font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-40">+ وسم</button>
            </div>
          </div>
          <div>
            <label :for="assignId" class="block text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">الموكل إليه</label>
            <select v-if="canAssign" :id="assignId" :value="thread.assigned_to ?? ''" @change="$emit('assign', $event.target)"
                    class="w-full px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
              <option value="">— بلا توكيل</option>
              <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <p v-else class="text-xs text-gray-600 dark:text-gray-300">{{ thread.assignee || '— بلا توكيل' }}</p>
          </div>
          <ThreadLabels v-if="flags.wa_labels" embedded :conversation-id="thread.id" :current="thread.labels || []" :all="labels" :can-reply="canReply"
                        :can-manage="canManageLabels" @changed="(l) => $emit('labelsChanged', thread.id, l)"/>
        </div>
      </PanelSection>

      <!-- 📝 الملاحظات -->
      <PanelSection id="notes" icon="📝" title="الملاحظات" :summary="notesSummary" default-open>
        <div class="space-y-3">
          <div v-if="flags.thread_pins" class="rounded-xl bg-amber-50/70 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-900/50 p-2.5">
            <p class="text-[11px] font-bold text-amber-900 dark:text-amber-200 mb-1.5">📌 الملاحظة المثبتة <span class="font-normal">— 🔒 داخلية، لا يراها العميل ولا البوت</span></p>
            <FactCard ref="factCard" embedded :conversation-id="thread.id" :can-reply="canReply"
                      @jump="(id) => $emit('jump', id)" @pins="(ids) => $emit('pins', ids)" @summary="(v) => (pinSummary = v)"/>
          </div>
          <div>
            <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">ملاحظات العميل التي يقرؤها البوت</p>
            <ul v-if="notes.length" class="space-y-1.5" data-test="panel-notes">
              <li v-for="(n, i) in shownNotes" :key="i" class="text-xs text-gray-700 dark:text-gray-200">
                <span class="px-1 py-px rounded text-[10px] me-1" :class="SOURCES[n.source]?.cls || SOURCES.system.cls">{{ SOURCES[n.source]?.label || n.source }}</span>
                <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ n.text }}</bdi>
                <span class="block text-[10px] text-gray-400"><bdi dir="ltr">{{ n.at }}</bdi></span>
              </li>
            </ul>
            <p v-else class="text-[11px] text-gray-400">لا ملاحظات بعد.</p>
            <button v-if="notes.length > 4" type="button" @click="allNotes = !allNotes" class="mt-1 min-h-8 text-[11px] font-bold text-gold-700 dark:text-gold-400">
              {{ allNotes ? 'عرض أقل' : `عرض الكل (${notes.length})` }}
            </button>
          </div>
        </div>
      </PanelSection>

      <!-- ✈️ الرحلات -->
      <PanelSection ref="tripsSection" id="trips" icon="✈️" title="الرحلات" :count="data ? data.trips.length : null" :summary="tripsSummary" default-open>
        <p v-if="loading && !data" class="text-xs text-gray-400">جارٍ التحميل...</p>
        <template v-else-if="data">
          <div v-if="data.trips.length" class="space-y-2">
            <TripCard v-for="(t, i) in data.trips" :key="t.key" :trip="t" :highlight="i === 0"/>
          </div>
          <p v-else class="text-[11px] text-gray-400">لا رحلة قادمة مسجلة على فاتورة.</p>
          <div v-if="data.past_trips.length" class="mt-2">
            <button type="button" @click="showPast = !showPast" :aria-expanded="showPast ? 'true' : 'false'" class="min-h-8 text-[11px] font-bold text-gray-600 dark:text-gray-300">
              {{ showPast ? '▴' : '▾' }} رحلات سابقة ({{ data.past_trips.length }})
            </button>
            <div v-if="showPast" class="space-y-2 mt-1">
              <TripCard v-for="t in data.past_trips" :key="t.key" :trip="t"/>
            </div>
          </div>
        </template>
      </PanelSection>

      <!-- 🧾 الفواتير: لمن يملك invoices.view وحده -->
      <PanelSection v-if="data?.invoices" id="invoices" icon="🧾" title="الفواتير" :count="data.invoices.length" :summary="invoicesSummary">
        <div v-if="data.summary" class="grid grid-cols-3 gap-1.5 mb-2 text-center">
          <div class="wp-tile"><p class="wp-tile-n">{{ data.summary.count }}</p><p class="wp-tile-l">فاتورة</p></div>
          <div class="wp-tile"><p class="wp-tile-n"><bdi dir="ltr">{{ jod(data.summary.approved_total) }}</bdi></p><p class="wp-tile-l">معتمد (د.أ)</p></div>
          <div class="wp-tile"><p class="wp-tile-n" :class="data.summary.pending_count ? 'text-amber-700 dark:text-amber-300' : ''">{{ data.summary.pending_count }}</p><p class="wp-tile-l">بانتظار</p></div>
        </div>
        <div v-if="data.invoices.length > 3" class="flex gap-1 mb-2" role="group" aria-label="تصفية الفواتير">
          <button v-for="f in INVOICE_FILTERS" :key="f.key" type="button" @click="invoiceFilter = f.key" :aria-pressed="invoiceFilter === f.key ? 'true' : 'false'"
                  class="min-h-7 px-2 rounded-full text-[11px] border"
                  :class="invoiceFilter === f.key ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/20 text-gold-800 dark:text-gold-300 font-bold' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'">{{ f.label }}</button>
        </div>
        <ul v-if="filteredInvoices.length" class="divide-y divide-gray-100 dark:divide-gray-800" data-test="panel-invoices">
          <li v-for="inv in shownInvoices" :key="inv.id">
            <a :href="inv.url" class="block py-2 -mx-1 px-1 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60">
              <span class="flex items-center gap-1.5">
                <bdi dir="ltr" class="font-mono text-[11px] text-gray-700 dark:text-gray-200">{{ inv.number }}</bdi>
                <span class="px-1.5 py-px rounded text-[10px]" :class="STATUS_CLS[inv.status] || STATUS_CLS.draft">{{ inv.status_label }}</span>
                <span class="ms-auto text-xs font-bold tabular-nums text-gray-900 dark:text-white"><bdi dir="ltr">{{ jod(inv.total) }}</bdi></span>
              </span>
              <span class="block text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">
                {{ shortDay(inv.date) }}<template v-if="inv.offer"> · <bdi dir="auto">{{ inv.offer }}</bdi><template v-if="inv.seats"> × {{ inv.seats }}</template></template><template v-if="inv.trip_date"> · ✈️ {{ shortDay(inv.trip_date) }}</template>
              </span>
            </a>
          </li>
        </ul>
        <p v-else class="text-[11px] text-gray-400">{{ data.invoices.length ? 'لا فواتير بهذه الحالة.' : 'لا فواتير على هذا العميل أو الرقم.' }}</p>
        <button v-if="filteredInvoices.length > 6" type="button" @click="allInvoices = !allInvoices" class="mt-1 min-h-8 text-[11px] font-bold text-gold-700 dark:text-gold-400">
          {{ allInvoices ? 'عرض أقل' : `عرض الكل (${filteredInvoices.length})` }}
        </button>
      </PanelSection>

      <!-- 💬 طلبات التسعير -->
      <PanelSection v-if="data" id="quotes" icon="💬" title="طلبات التسعير" :count="data.quotes.length" :summary="quotesSummary">
        <ul v-if="data.quotes.length" class="divide-y divide-gray-100 dark:divide-gray-800" data-test="panel-quotes">
          <li v-for="q in data.quotes" :key="q.number" class="py-2">
            <component :is="q.url ? 'a' : 'div'" :href="q.url" class="block" :class="q.url ? 'hover:underline' : ''">
              <span class="flex items-center gap-1.5">
                <bdi dir="ltr" class="font-mono text-[11px] text-gray-700 dark:text-gray-200">{{ q.number }}</bdi>
                <span class="px-1.5 py-px rounded text-[10px]" :class="q.open ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ q.status_label }}</span>
                <span class="ms-auto text-[10px] text-gray-400">{{ shortDay(q.date) }}</span>
              </span>
              <span v-if="q.offer || q.pax || q.depart_date" class="block text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">
                <template v-if="q.offer"><bdi dir="auto">{{ q.offer }}</bdi></template><template v-if="q.pax"> · 👥 {{ q.pax }}</template><template v-if="q.depart_date"> · ✈️ {{ shortDay(q.depart_date) }}</template>
              </span>
            </component>
          </li>
        </ul>
        <p v-else class="text-[11px] text-gray-400">لا طلبات تسعير من هذه المحادثة.</p>
      </PanelSection>

      <!-- ℹ️ عن المحادثة -->
      <PanelSection v-if="data" id="about" icon="ℹ️" title="عن المحادثة">
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]">
          <dt class="text-gray-500">بدأت</dt><dd class="text-gray-800 dark:text-gray-200"><bdi dir="ltr">{{ data.conversation.started_at || '—' }}</bdi></dd>
          <dt class="text-gray-500">آخر رسالة منه</dt><dd class="text-gray-800 dark:text-gray-200"><bdi dir="ltr">{{ data.conversation.last_inbound_at || '—' }}</bdi></dd>
          <dt class="text-gray-500">الرسائل</dt><dd class="text-gray-800 dark:text-gray-200 tabular-nums">{{ data.conversation.messages_in }} واردة · {{ data.conversation.messages_out }} صادرة</dd>
          <template v-if="data.conversation.profile_name">
            <dt class="text-gray-500">اسم واتساب</dt><dd class="text-gray-800 dark:text-gray-200" title="يضعه العميل بنفسه — غير موثّق"><bdi dir="auto">{{ data.conversation.profile_name }}</bdi></dd>
          </template>
          <template v-if="data.conversation.username">
            <dt class="text-gray-500">اسم المستخدم</dt><dd class="text-gray-800 dark:text-gray-200"><bdi dir="ltr" class="font-mono">@{{ data.conversation.username }}</bdi></dd>
          </template>
          <template v-if="thread.entry">
            <dt class="text-gray-500">المصدر</dt>
            <dd class="text-sky-700 dark:text-sky-300">{{ entryText }}</dd>
          </template>
        </dl>
      </PanelSection>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import axios from 'axios';
import FactCard from '../FactCard.vue';
import ThreadLabels from '../ThreadLabels.vue';
import PanelSection from './PanelSection.vue';
import TripCard from './TripCard.vue';
import { day, jod, shortDay } from './panelFormat.js';
import { colorFor, initials } from '@/composables/whatsapp/useInitials.js';
import { formatPhone } from '@/composables/whatsapp/usePhoneFormat.js';
import { customBounds, snoozeOptions } from '@/composables/whatsapp/useSnoozeOptions.js';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  thread: { type: Object, required: true },
  drawer: Boolean,
  flags: { type: Object, default: () => ({}) },
  staff: { type: Array, default: () => [] },
  topics: { type: Object, default: () => ({}) },
  labels: { type: Array, default: () => [] },
  canManageLabels: Boolean,
  canReply: Boolean,
  canAssign: Boolean,
  canToggleBot: Boolean,
  canLinkClient: Boolean,
  canReprocess: Boolean,
  canBlock: Boolean,
  canExport: Boolean,
  followup: Boolean,
});
const emit = defineEmits(['close', 'action', 'link', 'unlink', 'toggleBot', 'setTopic', 'assign', 'block', 'export', 'labelsChanged', 'jump', 'pins', 'badge']);

const SOURCES = {
  bot: { label: '🤖 البوت', cls: 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' },
  staff: { label: '👤 موظف', cls: 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300' },
  system: { label: '⚙️ النظام', cls: 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300' },
};
const STATUS_CLS = {
  approved: 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300',
  pending: 'bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300',
  editing: 'bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300',
  draft: 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300',
};
const INVOICE_FILTERS = [{ key: 'all', label: 'الكل' }, { key: 'approved', label: 'معتمدة' }, { key: 'pending', label: 'بانتظار' }];

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const heading = ref(null);
const factCard = ref(null);
const tripsSection = ref(null);
const data = ref(null);
const loading = ref(false);
const error = ref('');
let seq = 0;

async function load() {
  const id = props.thread.id;
  const mine = ++seq;
  loading.value = true;
  error.value = '';
  try {
    const { data: body } = await axios.get(`/api/whatsapp/conversations/${id}/panel`, api);
    if (mine === seq) data.value = body;
  } catch (e) {
    if (mine === seq) error.value = errorText(e, 'تعذّر تحميل بيانات العميل');
  } finally {
    if (mine === seq) loading.value = false;
  }
}

// ===== الحالة المحلية لكل محادثة =====
const newTopic = ref('');
const custom = ref('');
const copied = ref(false);
const showPast = ref(false);
const allNotes = ref(false);
const allInvoices = ref(false);
const invoiceFilter = ref('all');
const pinSummary = ref('');
const customId = `panel-snooze-${useId()}`;
const assignId = `panel-assign-${useId()}`;

watch(() => props.thread.id, () => {
  data.value = null;
  newTopic.value = '';
  custom.value = '';
  showPast.value = false;
  allNotes.value = false;
  allInvoices.value = false;
  invoiceFilter.value = 'all';
  load();
}, { immediate: true });
// ربط أو فك ربط بعميل يغيّر الفواتير والرحلات
watch(() => props.thread.client_id, (now, before) => { if (now !== before) load(); });

// ===== الهوية والحالة =====
const hhmm = (iso) => new Date(iso).toLocaleTimeString('ar-JO-u-nu-latn', { hour: '2-digit', minute: '2-digit' });
const windowText = computed(() => {
  const left = Number(props.thread.window_left);
  if (!Number.isFinite(left) || left <= 0) return 'مفتوحة';
  const h = Math.floor(left / 60);
  const m = Math.round(left % 60);
  return h ? `${h}س ${m}د باقية` : `${m}د باقية`;
});
async function copyPhone() {
  try {
    await navigator.clipboard.writeText(`+${props.thread.phone}`);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1500);
  } catch { /* الحافظة غير متاحة */ }
}
const blockable = computed(() => props.canBlock && !props.thread.blocked && !props.thread.is_admin && !props.thread.is_staff);
const entryText = computed(() => {
  const e = props.thread.entry;
  if (!e) return '';
  if (e.channel === 'ad') return `📣 إعلان${e.headline ? `: ${e.headline}` : ''}`;
  return `${e.channel === 'qr' ? '📷 رمز' : '🔗 رابط'}${e.title ? `: ${e.title}` : ''}`;
});

// ===== الإجراءات =====
const snooze = computed(() => snoozeOptions(props.thread.window_left));
const bounds = computed(() => customBounds(props.thread.window_left));
function act(name, payload) {
  custom.value = '';
  emit('action', name, payload);
}

// ===== التنظيم =====
const addable = computed(() => {
  const attached = (props.thread.topics || []).map((t) => t.key);
  return Object.entries(props.topics).filter(([key]) => !attached.includes(key)).map(([key, label]) => ({ key, label }));
});
function addTopic() {
  if (!newTopic.value) return;
  const key = newTopic.value;
  newTopic.value = '';
  emit('setTopic', key, true);
}
const organizeSummary = computed(() => {
  const parts = [];
  const topics = (props.thread.topics || []).map((t) => t.label);
  parts.push(topics.length ? topics.join('، ') : 'بلا وسوم');
  parts.push(props.thread.assignee ? `موكلة: ${props.thread.assignee}` : 'بلا توكيل');
  const labels = (props.thread.labels || []).length;
  if (labels) parts.push(`${labels} تصنيف`);
  return parts.join(' · ');
});

// ===== الملاحظات =====
const notes = computed(() => data.value?.notes || []);
const shownNotes = computed(() => (allNotes.value ? notes.value : notes.value.slice(0, 4)));
const notesSummary = computed(() => pinSummary.value || (notes.value.length ? `${notes.value.length} ملاحظات` : ''));

// ===== الرحلات =====
const nextTrip = computed(() => data.value?.trips?.[0] || null);
const badgeText = computed(() => {
  const t = nextTrip.value;
  if (!t) return '';
  if (t.phase === 'travelling') return '🧳 مسافر الآن';
  if (t.days_until === 1) return '✈️ يسافر غداً';
  if (t.days_until === 2) return '✈️ يسافر بعد يومين';
  return `✈️ يسافر بعد ${t.days_until} ${t.days_until <= 10 ? 'أيام' : 'يوماً'}`;
});
watch(badgeText, (v) => emit('badge', v), { immediate: true });
const tripsSummary = computed(() => (nextTrip.value ? `${badgeText.value} · ${nextTrip.value.offer?.title || 'فاتورة يدوية'}` : (data.value?.past_trips?.length ? 'لا رحلة قادمة' : '')));
async function focusTrips() {
  tripsSection.value?.open();
  await nextTick();
  tripsSection.value?.$el?.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
}

// ===== الفواتير والطلبات =====
const filteredInvoices = computed(() => {
  const list = data.value?.invoices || [];
  if (invoiceFilter.value === 'approved') return list.filter((i) => i.status === 'approved');
  if (invoiceFilter.value === 'pending') return list.filter((i) => i.status === 'pending' || i.status === 'editing');
  return list;
});
const shownInvoices = computed(() => (allInvoices.value ? filteredInvoices.value : filteredInvoices.value.slice(0, 6)));
const invoicesSummary = computed(() => {
  const s = data.value?.summary;
  if (!s) return '';
  return `${s.approved_count} معتمدة بـ ${jod(s.approved_total)} د.أ${s.pending_count ? ` · ${s.pending_count} بانتظار` : ''}`;
});
const quotesSummary = computed(() => {
  const open = (data.value?.quotes || []).filter((q) => q.open).length;
  return open ? `${open} مفتوح` : '';
});

defineExpose({
  reload: load,
  setPinned: (p) => factCard.value?.setPinned(p),
  focus: () => heading.value?.focus(),
});
</script>

<style>
/* أسماء wp- مخصصة لهذه اللوحة: ورقة عامة كي تعمل قواعد .dark (‏:global داخل scoped يُسقط ما بعده) */
.wp-chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 11px; line-height: 1.25rem; }
.wp-chip-gray { background: rgb(243 244 246); color: rgb(75 85 99); }
.wp-chip-green { background: rgb(240 253 244); color: rgb(21 128 61); }
.wp-chip-sky { background: rgb(240 249 255); color: rgb(3 105 161); }
.wp-chip-red { background: rgb(254 242 242); color: rgb(185 28 28); }
.dark .wp-chip-gray { background: rgb(31 41 55); color: rgb(209 213 219); }
.dark .wp-chip-green { background: rgb(20 83 45 / 0.3); color: rgb(134 239 172); }
.dark .wp-chip-sky { background: rgb(12 74 110 / 0.3); color: rgb(125 211 252); }
.dark .wp-chip-red { background: rgb(127 29 29 / 0.3); color: rgb(252 165 165); }

.wp-act { display: flex; align-items: center; justify-content: flex-start; min-height: 2.5rem; padding: 0.375rem 0.625rem; border-radius: 0.625rem;
  border: 1px solid rgb(229 231 235); font-size: 12px; font-weight: 700; text-align: start; color: rgb(55 65 81); }
.wp-act:hover:not(:disabled) { background: rgb(249 250 251); }
.wp-act:disabled { opacity: 0.4; cursor: not-allowed; }
.wp-act-green { border-color: rgb(134 239 172); color: rgb(21 128 61); background: rgb(240 253 244); }
.wp-act-blue { border-color: rgb(147 197 253); color: rgb(29 78 216); }
.wp-act-red { border-color: rgb(252 165 165); color: rgb(185 28 28); }
.wp-act-purple { border-color: rgb(216 180 254); color: rgb(126 34 206); }
.dark .wp-act { border-color: rgb(55 65 81); color: rgb(209 213 219); }
.dark .wp-act:hover:not(:disabled) { background: rgb(31 41 55); }
.dark .wp-act-green { border-color: rgb(22 101 52); color: rgb(134 239 172); background: rgb(20 83 45 / 0.25); }
.dark .wp-act-blue { border-color: rgb(30 64 175); color: rgb(147 197 253); }
.dark .wp-act-red { border-color: rgb(153 27 27); color: rgb(252 165 165); }
.dark .wp-act-purple { border-color: rgb(107 33 168); color: rgb(216 180 254); }

.wp-tile { border-radius: 0.625rem; background: rgb(249 250 251); padding: 0.375rem 0.25rem; }
.wp-tile-n { font-size: 13px; font-weight: 700; color: rgb(17 24 39); font-variant-numeric: tabular-nums; }
.wp-tile-l { font-size: 10px; color: rgb(107 114 128); }
.dark .wp-tile { background: rgb(31 41 55 / 0.6); }
.dark .wp-tile-n { color: rgb(255 255 255); }
.dark .wp-tile-l { color: rgb(156 163 175); }
</style>
