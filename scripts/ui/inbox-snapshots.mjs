// لقطات صندوق المحادثات قبل الهيكلة وبعدها (E16.1). تطوير فقط، على Demo أو نسخة محلية ببيانات اصطناعية، لا بيانات عملاء.
//
//   BASE=https://demo.example EMAIL=... PASSWORD=... CHROME_PATH="C:/Program Files/Google/Chrome/Application/chrome.exe" \
//   OUT=../inbox-shots CONV_IDS=1,2,3 node scripts/ui/inbox-snapshots.mjs
//
// CHROME_ARGS اختيارية. CONV_IDS: محادثات الحالات بالترتيب (عادية، نافذة مغلقة، إدارة، إنجليزية، تنسيق ورابط). الصور تُحفظ في OUT خارج المستودع.
// QUERY اختيارية تُلحق بالرابط، مثل QUERY=v2=1 لمعاينة الصندوق الجديد بحساب مدير قبل تفعيل العلم inbox_v2.
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';

const { BASE, EMAIL, PASSWORD, CHROME_PATH } = process.env;
const OUT = process.env.OUT || '../inbox-shots';
const CONVS = (process.env.CONV_IDS || '').split(',').filter(Boolean);
const QUERY = process.env.QUERY ? `&${process.env.QUERY}` : '';
if (!BASE || !EMAIL || !PASSWORD || !CHROME_PATH) {
    console.error('BASE وEMAIL وPASSWORD وCHROME_PATH مطلوبة');
    process.exit(1);
}

const SIZES = [['mobile', 390, 844], ['tablet', 820, 1180], ['desktop', 1440, 900]];
const THEMES = ['light', 'dark'];
mkdirSync(OUT, { recursive: true });

// CHROME_ARGS اختيارية (مثل --no-sandbox داخل حاوية بصلاحية root)
const browser = await puppeteer.launch({ executablePath: CHROME_PATH, headless: true, args: (process.env.CHROME_ARGS || '').split(' ').filter(Boolean) });
const page = await browser.newPage();
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.waitForSelector('input[type=email]');
await new Promise((r) => setTimeout(r, 500));   // بعد تفعيل Vue للنموذج، وإلا ضاع المكتوب
await page.type('input[type=email]', EMAIL);
await page.type('input[type=password]', PASSWORD);
await page.click('button[type=submit]');
// الدخول طلب Inertia (XHR) لا تنقّل كامل: ننتظر مغادرة صفحة الدخول
await page.waitForFunction(() => location.pathname !== '/login', { timeout: 20000 });

for (const theme of THEMES) {
    // السمة كما يحفظها AppLayout
    await page.evaluate((t) => localStorage.setItem('nusuk-theme', t), theme);
    for (const [name, width, height] of SIZES) {
        await page.setViewport({ width, height });
        await page.goto(`${BASE}/whatsapp/inbox?${QUERY.slice(1)}`, { waitUntil: 'networkidle2' });
        await page.screenshot({ path: join(OUT, `${theme}-${name}-list.png`) });
        for (const id of CONVS) {
            await page.goto(`${BASE}/whatsapp/inbox?conv=${id}${QUERY}`, { waitUntil: 'networkidle2' });
            await new Promise((r) => setTimeout(r, 600));
            await page.screenshot({ path: join(OUT, `${theme}-${name}-conv${id}.png`) });
        }
    }
}
await browser.close();
console.log(`اللقطات في ${OUT}`);
