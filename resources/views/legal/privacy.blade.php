@extends('legal.layout')
@section('title', 'سياسة الخصوصية')
@section('desc', 'سياسة خصوصية شركة نُسك للسياحة والسفر — كيف نجمع بيانات عملائنا ونستخدمها ونحميها، بما في ذلك المراسلة عبر واتساب.')

@section('content')

<p>تُوضّح هذه السياسة كيف تجمع <strong>شركة نُسك للسياحة والسفر</strong> (ونشير إليها بـ«نحن») بياناتك وتستخدمها وتحميها عند استخدامك خدماتنا، بما في ذلك مراسلتنا عبر <strong>واتساب</strong>.</p>

<h2>١ · البيانات التي نجمعها</h2>
<table>
    <tr><th>الفئة</th><th>أمثلة</th><th>مصدرها</th></tr>
    <tr><td>بيانات التعريف</td><td>الاسم، رقم الهاتف، البريد الإلكتروني، اسم الشركة</td><td>تزوّدنا بها عند التعامل معنا</td></tr>
    <tr><td>محتوى المراسلات</td><td>نصّ رسائلك على واتساب وردودنا عليها</td><td>محادثتك معنا</td></tr>
    <tr><td>بيانات الحجز</td><td>وجهة السفر، تاريخ الرحلة، عدد المسافرين</td><td>طلبك</td></tr>
    <tr><td>البيانات المالية</td><td>الفواتير، المبالغ المدفوعة والمتبقية</td><td>تعاملاتك معنا</td></tr>
    @if ($mediaArchive ?? false)
    <tr><td>المرفقات</td><td>صور ووثائق ترسلها لنا مثل الجواز أو إيصال الحوالة</td><td>محادثتك معنا</td></tr>
    @endif
    <tr><td>بيانات تقنية</td><td>معرّف الرسالة، وقت الإرسال، حالة التسليم</td><td>منصّة واتساب</td></tr>
</table>

<div class="box gold">
    <strong>لا نجمع</strong> أرقام بطاقات الدفع ولا كلمات المرور عبر واتساب إطلاقاً. لا ترسل هذه البيانات في المحادثة.
</div>

<h2>٢ · كيف نستخدم بياناتك</h2>
<ul>
    <li>الردّ على استفساراتك وتقديم عروض الأسعار.</li>
    <li>إتمام حجوزاتك وإصدار فواتيرك ومتابعة مدفوعاتك.</li>
    <li>إعلامك بمعلومات تخصّ رحلتك أو حجزك القائم.</li>
    <li>حفظ سجلّ تعاملاتك لأغراض محاسبية وقانونية.</li>
    <li>تحسين جودة خدمتنا.</li>
</ul>
<p><strong>لا نستخدم بياناتك لأي غرض آخر، ولا نرسل لك رسائل تسويقية دون موافقتك.</strong></p>

<h2>٣ · المراسلة عبر واتساب</h2>
<p>نستخدم <em>WhatsApp Business Platform</em> من شركة Meta لإدارة محادثاتنا معك:</p>
<ul>
    <li>قد يردّ عليك <strong>مساعد آليّ</strong> في البداية، ويمكنك في أي وقت طلب التحدّث مع موظف فيُحوَّل حديثك مباشرةً.</li>
    <li>تُحفظ محادثتك في نظامنا ليتمكّن موظفونا من متابعة طلبك.</li>
    <li>تمرّ الرسائل عبر خوادم Meta وتخضع أيضاً لسياسة خصوصية واتساب.</li>
    <li>الموقع الذي تشاركه في المحادثة، وبطاقة جهة الاتصال التي ترسلها، يُحفظان معها ويراهما الموظف المخوّل وحده. ولا يصل رقم من بطاقة تشاركها إلى مزوّد الذكاء الاصطناعي.</li>
    @if ($mediaArchive ?? false)
    <li>الصور والوثائق التي ترسلها تُحفظ على خوادمنا بوصول مقيّد للموظف المختص، ويُسجَّل كل اطّلاع عليها. ولا يطلب منك المساعد الآلي صورة جوازك أو هويتك. ويمكنك اختيار «احذف ملفاتي» فتُحذف فوراً.</li>
    @endif
    <li>لإيقاف رسائلنا أرسل كلمة <strong>«إيقاف»</strong> في المحادثة.</li>
    <li>قد نحظر الأرقام التي ترسل محتوى مسيئاً أو احتيالياً، عبر أداة الحظر الرسمية في واتساب. وإن طلب صاحب رقم محظور حذف بياناته يبقى الحظر قائماً ببصمة لا تكشف الرقم، وبلا نص سببه.</li>
    <li>نسجّل مصدر بدء المحادثة (رمز QR أو رابط أو إعلان) لقياس حملاتنا، دون مشاركته مع أي طرف.</li>
    <li>قد نرسل لك قائمة أو أزرار تختار منها، أو نموذج طلب تسعير تعبّئه داخل واتساب. يصلنا اختيارك أو طلبك كأي رسالة، ولا يطلب النموذج رقم جواز أو هوية، ونحذف من تفاصيله أي رقم يشبهها.</li>
</ul>

<h2>٤ · مشاركة البيانات</h2>
<p><strong>نحن لا نبيع بياناتك ولا نؤجّرها لأي جهة.</strong> نشاركها فقط في الحدود الضرورية مع:</p>
<ul>
    <li><strong>مزوّدي خدمات السفر</strong> (شركات الطيران، الفنادق، شركات النقل، جهات التأشيرات) — بالقدر اللازم لإتمام حجزك فقط.</li>
    <li><strong>Meta (واتساب)</strong> — كونها مشغّل قناة المراسلة.</li>
    <li><strong>مزوّد خدمة الذكاء الاصطناعي</strong> (<bdi>{{ implode('، ', $aiProviders ?? ['Google Gemini']) }}</bdi>) — يُعالَج نصّ المحادثة لتوليد الردّ{{ ($staffTranslation ?? false) ? '، وترجمة الرسائل عند طلب الموظف' : '' }}، ولا يُستخدم لتدريب نماذج عامة.</li>
    @if ($integrationsOutbound ?? false)
    <li><strong>أدوات أتمتة داخلية نشغّلها نحن</strong> (مثل n8n) — تتلقى أرقام الطلبات والفواتير وحالاتها فقط، دون محتوى محادثاتك أو وثائقك أو رقم هاتفك.</li>
    @endif
    @if ($mcpReports ?? false)
    <li><strong>مساعد ذكاء اصطناعي يستعمله مدير الشركة</strong> — لقراءة تقارير داخلية مجمّعة (المبيعات والاعتمادات والمسافرون)، دون محتوى محادثاتك أو أرقام الهواتف.</li>
    @endif
    <li><strong>الجهات الرسمية</strong> — عند وجود إلزام قانوني.</li>
</ul>

<h2>٥ · مدّة الاحتفاظ</h2>
<ul>
    <li><strong>المحادثات:</strong> حتى 24 شهراً من آخر تواصل، ما لم ترتبط بفاتورة.</li>
    <li><strong>إشعارات واتساب التي تعذّرت معالجتها:</strong> نسخة مشفّرة تُحفظ {{ $webhookHours ?? 72 }} ساعة كحد أقصى لإعادة المعالجة، ثم تُمحى تلقائياً.</li>
    <li><strong>إحصاءات استعمال المساعد الآلي:</strong> 12 شهراً، ثم تُجمَّع شهرياً بلا ربط بشخص.</li>
    <li><strong>الإشعارات الداخلية للموظفين:</strong> حتى 6 أشهر.</li>
    <li><strong>التصنيفات والملاحظات الداخلية:</strong> تُحذف مع المحادثة.</li>
    <li><strong>السجلات المالية (الفواتير والسندات):</strong> للمدّة التي يفرضها القانون الأردني، ولا يمكن حذفها قبلها.</li>
    <li><strong>بيانات التعريف:</strong> طوال كونك عميلاً لدينا.</li>
    @if ($mediaArchive ?? false)
    <li><strong>المرفقات (صور ووثائق):</strong> {{ $mediaDays }} يوماً من استلامها ثم تُحذف.</li>
    @endif
    <li><strong>النسخ الاحتياطية:</strong> مشفّرة، وتُحفظ 6 أشهر كحدّ أقصى ثم تُتلف.</li>
</ul>

<h2>٦ · حقوقك</h2>
<ul>
    <li>الاطّلاع على البيانات التي نحتفظ بها عنك: نرسل لك نسخة منها خلال 30 يوماً بعد التحقّق من هويتك.</li>
    <li>تصحيح أي بيان غير دقيق.</li>
    <li>طلب حذف بياناتك — راجع صفحة <a href="/data-deletion">حذف البيانات</a>.</li>
    <li>الاعتراض على تلقّي أي رسائل منا.</li>
</ul>

<h2>٧ · أمان البيانات</h2>
<p>نحمي بياناتك بالاتصال المشفّر (HTTPS)، وبتشفير مفاتيح الوصول الحسّاسة، وبنظام صلاحيات يقصر الاطّلاع على الموظفين المخوّلين، وبالتحقّق من توقيع كل رسالة واردة من واتساب.</p>
<p>يقتصر البحث في المحادثات وتصديرها على الموظفين المخوّلين، كلٌّ ضمن المحادثات المسندة إليه، وكل تصدير مسجّل باسم من قام به وسببه.</p>

<h2>٨ · الأطفال</h2>
<p>خدماتنا موجّهة لمن أتمّ 18 عاماً. لا نجمع بيانات الأطفال عن قصد.</p>

<h2>٩ · التعديلات</h2>
<p>قد نحدّث هذه السياسة، وسيظهر تاريخ آخر تحديث أعلى الصفحة.</p>

<h2>١٠ · التواصل معنا</h2>
<div class="box">
    <strong>شركة نُسك للسياحة والسفر</strong><br>
    المملكة الأردنية الهاشمية<br>
    البريد الإلكتروني: <a href="mailto:info@nusuk-jo.com">info@nusuk-jo.com</a>
</div>

<h2 id="security">١١ · الإبلاغ عن ثغرة أمنية</h2>
<p>للإبلاغ عن ثغرة أمنية في أنظمتنا: <a href="mailto:info@nusuk-jo.com">info@nusuk-jo.com</a> بعنوان «ثغرة أمنية» — نرد خلال 72 ساعة. لا تختبر على بيانات عملاء حقيقية، ولا ترسل رسائل واتساب لأرقام لا تملكها، ولا تعطّل الخدمة. البحث بحسن نية ضمن هذه الحدود لا نلاحقه قانونياً.</p>

<div class="en">
    <h2>Privacy Policy (English Summary)</h2>
    <p><strong>NUSK Travel &amp; Tourism</strong> (Jordan) collects your name, phone number, message content, booking details and billing records in order to answer your enquiries, issue quotations, complete your bookings and keep lawful accounting records.</p>
    <h3>WhatsApp</h3>
    <p>We use the WhatsApp Business Platform by Meta. An automated assistant may answer first; you may ask for a human agent at any time and the conversation is handed over. Messages transit Meta's servers and are also governed by WhatsApp's own privacy policy. Send <strong>"STOP"</strong> to opt out of our messages. We may block numbers that send abusive or fraudulent content, using WhatsApp's official blocking tool; if the owner of a blocked number asks us to delete their data, the block stays in place under a fingerprint that does not reveal the number, without the reason text. We record how a conversation started (a QR code, a link or an ad) to measure our campaigns, without sharing it with anyone.</p>
    <h3>Sharing</h3>
    <p>We <strong>do not sell or rent</strong> your data. We share it only as needed with travel suppliers (airlines, hotels, transport, visa providers), with Meta as the messaging channel operator, with our AI provider{{ count($aiProviders ?? []) > 1 ? 's' : '' }} ({{ implode(', ', $aiProviders ?? ['Google Gemini']) }}) solely to generate replies{{ ($staffTranslation ?? false) ? ' and to translate messages when a staff member asks' : '' }} (not used to train general models){{ ($integrationsOutbound ?? false) ? ', with internal automation tools we run ourselves (such as n8n), which receive only request and invoice numbers and their status, never your conversations, documents or phone number' : '' }}{{ ($mcpReports ?? false) ? ', with an AI assistant used by the company manager to read aggregated internal reports, without your conversations or phone numbers' : '' }}, and with authorities where legally required.</p>
    <h3>Retention</h3>
    @if ($mediaArchive ?? false)
    <p>Attachments you send (photos, documents such as a passport or a transfer receipt) are stored on our servers with access limited to the staff handling your booking, every view is logged, and they are deleted {{ $mediaDays }} days after receipt — or at once if you choose "Delete my files".</p>
    @endif
    <p>Conversations: up to 24 months after last contact, unless attached to an invoice. Assistant usage statistics: 12 months, then aggregated monthly without any link to a person. Internal staff notifications: up to 6 months. Internal labels and notes: deleted with the conversation. WhatsApp notifications we could not process: an encrypted copy for at most {{ $webhookHours ?? 72 }} hours, then wiped automatically. Financial records: for the period required by Jordanian law. Backups: encrypted, kept for at most 6 months, then destroyed.</p>
    <h3>Security</h3>
    <p>Searching and exporting conversations is limited to authorised staff, each within the conversations assigned to them, and every export is logged with who made it and why.</p>
    <h3>Your rights</h3>
    <p>You may access your data (we send you a copy within 30 days after verifying your identity), correct it, or request its deletion — see our <a href="/data-deletion">Data Deletion</a> page — and object to receiving messages.</p>
    <h3>Contact</h3>
    <p>NUSK Travel &amp; Tourism, Jordan — <a href="mailto:info@nusuk-jo.com">info@nusuk-jo.com</a></p>
    <h3 id="security-en">Reporting a security vulnerability</h3>
    <p>Email <a href="mailto:info@nusuk-jo.com">info@nusuk-jo.com</a> with the subject "Security vulnerability" — we reply within 72 hours. Do not test on real customer data, do not message WhatsApp numbers you do not own, and do not disrupt the service. Good-faith research within these limits will not face legal action.</p>
</div>

@endsection
