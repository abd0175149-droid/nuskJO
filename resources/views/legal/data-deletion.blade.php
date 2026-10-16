@extends('legal.layout')
@section('title', 'حذف البيانات')
@section('desc', 'كيف تطلب حذف بياناتك الشخصية ومحادثاتك من أنظمة شركة نُسك للسياحة والسفر.')

@section('content')

<p>يحقّ لك طلب حذف بياناتك الشخصية ومحادثاتك من أنظمتنا في أي وقت. توضّح هذه الصفحة الطريقة والمدّة وما الذي يُحذف فعلاً.</p>

<h2>كيف تطلب الحذف</h2>

<div class="box gold">
    <h3 style="margin-top:0">الطريقة الأولى — عبر واتساب (الأسرع)</h3>
    <p style="margin:0">أرسل من <strong>نفس الرقم</strong> الذي راسلتنا منه كلمة:</p>
    <p style="margin:8px 0;font-size:20px;font-weight:700">حذف بياناتي</p>
    <p style="margin:0">يُسجَّل طلبك تلقائياً ويصلك تأكيد استلام فوري، ثم يتحقّق موظف مختصّ من هويتك وينفّذ الطلب.</p>
</div>

<div class="box">
    <h3 style="margin-top:0">الطريقة الثانية — عبر البريد الإلكتروني</h3>
    <p>أرسل رسالة إلى <a href="mailto:info@nusuk-jo.com?subject=طلب%20حذف%20بيانات">info@nusuk-jo.com</a> بعنوان <strong>«طلب حذف بيانات»</strong> متضمّنة:</p>
    <ul>
        <li>اسمك الكامل</li>
        <li>رقم الهاتف الذي راسلتنا منه</li>
        <li>عبارة صريحة بطلب حذف بياناتك</li>
    </ul>
</div>

<h2>ماذا نحذف</h2>
<table>
    <tr><th>البيانات</th><th>الإجراء</th></tr>
    <tr><td>محادثاتك على واتساب (الرسائل الواردة والصادرة)</td><td>✅ تُحذف نهائياً</td></tr>
    @if ($mediaArchive ?? false)
    <tr><td>المرفقات (صور ووثائق)</td><td>✅ تُحذف نهائياً من أنظمتنا (ويمكنك حذفها وحدها فوراً بزر «احذف ملفاتي»)</td></tr>
    @endif
    <tr><td>إشعارات واتساب التي تعذّرت معالجتها (نسخة مشفّرة تُحفظ {{ $webhookHours ?? 72 }} ساعة كحد أقصى ثم تُمحى تلقائياً)</td><td>✅ تُحذف نهائياً</td></tr>
    <tr><td>الملاحظات المحفوظة عنك</td><td>✅ تُحذف نهائياً</td></tr>
    <tr><td>طلبات التسعير غير المكتملة</td><td>✅ تُحذف نهائياً</td></tr>
    <tr><td>بيانات التواصل (الاسم، الهاتف، البريد)</td><td>✅ تُحذف ما لم ترتبط بفواتير قائمة</td></tr>
    <tr><td>الفواتير والسندات المالية</td><td>⚠️ تُحفظ للمدّة القانونية</td></tr>
</table>

<h2>لماذا تبقى السجلات المالية؟</h2>
<p>يُلزمنا القانون الأردني بالاحتفاظ بالسجلات المحاسبية (الفواتير وسندات القبض والصرف) لمدّة محدّدة لأغراض ضريبية وتدقيقية. لا يمكننا حذفها قبل انقضاء هذه المدّة، وبعدها تُراجع للحذف. وطلبات التسعير المرتبطة بفاتورة تبقى ضمن هذا السجل المالي. وفي جميع الأحوال <strong>لا تُستخدم هذه السجلات لمراسلتك بعد طلب الحذف</strong>.</p>

<h2>النسخ الاحتياطية</h2>
<p>نحتفظ بنسخ احتياطية مشفّرة لمدّة أقصاها 6 أشهر. قد تبقى بياناتك المحذوفة فيها حتى انقضاء مدّتها ثم تُتلف، لكنها <strong>لا تُستعاد إلى أنظمتنا العاملة</strong>: إن احتجنا إلى استعادة نسخة، نعيد تطبيق كل طلبات الحذف السابقة قبل العودة إلى العمل.</p>

<h2>المدّة الزمنية</h2>
<ul>
    <li>نؤكّد استلام طلبك خلال <strong>يومي عمل</strong>.</li>
    <li>نُنفّذ الحذف خلال <strong>30 يوماً</strong> كحدّ أقصى من التحقّق من هويتك.</li>
    <li>نُعلمك عند اكتمال التنفيذ.</li>
</ul>

<h2>إيقاف الرسائل فقط (دون حذف)</h2>
<p>إن كنت ترغب بوقف رسائلنا مع بقاء سجلّ تعاملاتك، أرسل كلمة <strong>«إيقاف»</strong> على واتساب — يتوقف الإرسال فوراً دون حاجة لطلب حذف.</p>

<h2>ملاحظة عن واتساب</h2>
<p>الحذف يشمل أنظمتنا فقط. لا يحذف الرسائل المحفوظة على <strong>جهازك</strong>، وتحذف Meta نسختها من الوسائط خلال 30 يوماً وفق سياستها — لإدارتها راجع إعدادات تطبيق واتساب وسياسة خصوصية Meta.</p>
<p>إن كنت طلبت «إيقاف» من قبل، يبقى رقمك في قائمة الإيقاف وحدها حتى بعد الحذف، كي لا نراسلك.</p>

<h2>التواصل</h2>
<div class="box">
    <strong>شركة نُسك للسياحة والسفر</strong><br>
    المملكة الأردنية الهاشمية<br>
    البريد الإلكتروني: <a href="mailto:info@nusuk-jo.com">info@nusuk-jo.com</a>
</div>

<div class="en">
    <h2>Data Deletion Instructions (English)</h2>
    <p>You may request deletion of your personal data and conversations from <strong>NUSK Travel &amp; Tourism</strong> at any time.</p>
    <h3>How to request</h3>
    <ul>
        <li><strong>Via WhatsApp:</strong> send the message <strong>"حذف بياناتي"</strong> (or "delete my data") from the same number you contacted us with. The request is recorded automatically and you receive an immediate acknowledgement; a staff member then verifies your identity and processes it.</li>
        <li><strong>Via email:</strong> write to <a href="mailto:info@nusuk-jo.com?subject=Data%20Deletion%20Request">info@nusuk-jo.com</a> with the subject <strong>"Data Deletion Request"</strong>, including your full name, the phone number you contacted us from, and an explicit deletion request.</li>
    </ul>
    <h3>What is deleted</h3>
    <p>Your WhatsApp conversations (inbound and outbound messages){{ ($mediaArchive ?? false) ? ', attachments you sent (photos and documents)' : '' }}, any encrypted copy of a WhatsApp notification we could not process yet (kept {{ $webhookHours ?? 72 }} hours at most, then wiped automatically), stored notes about you, incomplete quotation requests, and your contact details — unless they are attached to existing invoices.</p>
    <h3>What is retained</h3>
    <p>Invoices and financial vouchers (and quotation requests attached to an invoice) are retained for the period required by Jordanian tax and audit law, then reviewed for deletion. They are never used to contact you after a deletion request.</p>
    <p>Deletion covers our systems only; Meta deletes its copy of media within 30 days, and messages on your device stay there. If you previously sent "STOP", your number stays on the opt-out list so we never message you again.</p>
    <h3>Backups</h3>
    <p>We keep encrypted backups for at most 6 months. Deleted data may remain in them until they expire and are destroyed, but it is <strong>never restored to our live systems</strong>: if we ever restore a backup, all earlier deletion requests are re-applied before we resume operation.</p>
    <h3>Timeframe</h3>
    <p>We acknowledge your request within <strong>2 business days</strong> and complete deletion within <strong>30 days</strong> of verifying your identity, then notify you.</p>
    <h3>Opt out only</h3>
    <p>To stop messages without deleting your records, send <strong>"STOP"</strong> on WhatsApp.</p>
    <p>Contact: <a href="mailto:info@nusuk-jo.com">info@nusuk-jo.com</a></p>
</div>

@endsection
