# رسائل الموظف الصوتية: التشغيل (E34)

## كيف تعمل
1. الموظف يضغط 🎤 في المنشئ. المتصفح يسجّل بـ MediaRecorder: Firefox بصيغة ogg، وChrome وAndroid بـ webm، وSafari بـ mp4. يتوقف التسجيل وحده عند دقيقتين.
2. «إرسال» يفتح تأكيد المستلم، ثم يذهب الملف إلى `POST api/whatsapp/conversations/{id}/send-file` بالحقل `voice=1` وترويسة `Idempotency-Key` (مفتاح واحد لكل تسجيل).
3. الخادم يفحص بالترتيب: الصلاحية `whatsapp.reply` ونطاق المحادثة، والعلم `staff_voice_notes`، والأقفال (النافذة والإيقاف والقناة) قبل أي عمل ثقيل، ونوع الملف الحقيقي (webm أو ogg أو mp4) وحجمه 3MB.
4. `MediaConverter` يحوّل إلى ogg/opus أحادي 48kHz بمعدل 32k. إن تجاوز الناتج 512KB يُعاد الترميز مرة بـ 24k، ثم يُرفض.
5. `VoiceNoteValidator` يتأكد من شرط ميتا: Ogg وOpusHead وقناة واحدة و512KB أو أقل.
6. نسخة خاصة على قرص `wa_media` تحت `outbound/`، وصف «قيد الإرسال»، ثم `SendWhatsAppMediaJob` في الطابور `wa-media-out`: يرفع الملف بالمعرّف ثم يرسل `{type: audio, audio: {id, voice: true}}`.
7. بعد الإرسال، ومع العلم `staff_voice_transcript`: `TranscribeStaffVoice` في الطابور `wa-media` يفرّغ النص عبر Gemini للزملاء.

## الحدود والحماية
- **تحويل واحد على الحاوية** بقفل في الكاش `wa:ffmpeg` (75 ثانية). الطلب الثاني ينتظر 3 ثوانٍ ثم يرد 503 «المحوّل مشغول»، ويبقى التسجيل في المتصفح لإعادة الإرسال بالمفتاح نفسه.
- **المهلة:** ffmpeg 45 ثانية وffprobe 5 ثوانٍ، فيبقى الطلب تحت مهلة كتلة nginx للمسار (60 ثانية). التحويل الفعلي لدقيقتين أقل من ثانية.
- **العملية** بوسائط مصفوفة بلا shell، وبيئتها `PATH` وحده (لا أسرار التطبيق)، وأسماء الملفات uuid يولّدها الخادم في `storage/app/tmp/voice` بصلاحية 0700. الملف الخام يُحذف دائماً، والناتج يُحذف عند أي فشل.
- **البوت لا يرسل صوتاً.** `WhatsAppChannel::sendVoice` بلا معامل مصدر، و`dispatch` يرفض حمولة صوتية لغير الموظف، والاختبار `NoBotAudioTest` يحرس ذلك.

## المراقبة
- `php artisan whatsapp:doctor`: سطر ffmpeg بالإصدار، وأعداد اليوم (تحويلات ومتوسط زمنها، و«مشغول»، وفشل).
- سجل الواتساب: `wa.voice.converted` (الحجم والمدة والزمن)، و`wa.voice.busy`، و`wa.voice.failed` (السبب ورمز الخروج وstderr مقصوصاً)، و`wa.voice.rejected` (السبب والنوع)، و`voice_out.transcribed` و`voice_out.transcription_failed`.
- الطابور: `jobs` حيث `queue='wa-media-out'` للإرسال، و`queue='wa-media'` للتفريغ.

## أعطال شائعة
- **الزر لا يظهر:** العلم مطفأ، أو ffmpeg غير موجود (`whatsapp:doctor`)، أو المتصفح لا يدعم التسجيل، أو النافذة مغلقة، أو الموظف بلا `whatsapp.reply`.
- **«لم يُسمح باستعمال الميكروفون»:** إذن المتصفح للموقع. تحقق أيضاً من `Permissions-Policy: microphone=(self)` بـ `curl -sI`.
- **503 متكرر:** تحويلات كثيرة معاً أو تحويل عالق. راجع `wa.voice.failed` و`docker stats`. القفل يُحرَّر وحده بعد 75 ثانية.
- **«بلا نص» تحت الفقاعة:** السبب مكتوب: المزوّد لا يقبل الصوت (Claude)، أو لم يؤكد المدير الطبقة المدفوعة، أو تعذّر النداء. الصوت يبقى قابلاً للاستماع.
- **ملفات في `storage/app/tmp/voice`:** عملية قُتلت قبل الحذف. `whatsapp:voice-tmp-sweep` يكنسها كل ساعة.

## الإطفاء
- العلم `staff_voice_notes` إلى off من إعدادات الواتساب، أو `WA_FEATURE_STAFF_VOICE_NOTES=false` ثم `docker compose up -d nuskjo-app`. يختفي الزر ويرد المسار 404.
- التفريغ وحده: `staff_voice_transcript` إلى off. تبقى الرسائل الصوتية بلا نص.
