<template>
    <AppLayout>
        <template #header>العروض والباقات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <label class="sr-only" for="offers-search">بحث</label>
                    <input id="offers-search" v-model="search" type="text" placeholder="بحث بعنوان العرض أو الفندق..." class="w-72 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <label class="sr-only" for="offers-category">التصنيف</label>
                    <select id="offers-category" v-model="categoryFilter" @change="applyFilters" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500">
                        <option value="">🗂️ كل التصنيفات</option>
                        <option v-for="c in categories" :key="c" :value="c">{{ catLabel(c) }}</option>
                    </select>
                </div>
                <button v-if="can?.create" @click="openModal(null)" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:shadow-gold-500/25 w-full sm:w-auto">+ إضافة عرض</button>
            </div>

            <!-- Bot info banner -->
            <div class="p-4 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">
                🤖 العروض التي تفعّل لها «ظاهر للبوت» هي وحدها التي يستخدمها بوت الواتساب في الردّ على العملاء.
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-5 py-3 text-right font-bold">العنوان</th>
                        <th class="px-5 py-3 text-right font-bold">السعر</th>
                        <th class="px-5 py-3 text-right font-bold">التواريخ</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الفندق</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                        <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="o in offers.data" :key="o.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="العنوان" class="px-5 py-3 text-right">
                                <div class="font-medium text-gray-800 dark:text-gray-100">{{ o.title }}</div>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-bold" :class="catChip(o.category)">{{ catLabel(o.category) }}</span>
                            </td>
                            <td data-label="السعر" class="px-5 py-3 text-right whitespace-nowrap">
                                <span class="font-bold font-mono text-xs tabular-nums text-gray-800 dark:text-gray-100" dir="ltr">{{ money(o.price_jod) }}</span>
                                <span class="text-xs text-gray-400"> د.أ {{ perLabel(o.price_per) }}</span>
                            </td>
                            <td data-label="التواريخ" class="px-5 py-3 text-right text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <span v-if="o.departure_date || o.return_date" dir="ltr" class="inline-block font-mono">{{ d(o.departure_date) }} → {{ d(o.return_date) }}</span>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td data-label="الفندق" class="px-5 py-3 text-right text-xs hide-mobile" :class="o.hotel_name ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400'">
                                {{ o.hotel_name || '—' }}
                                <span v-if="o.hotel_rating" class="block text-[11px] leading-4">{{ stars(o.hotel_rating) }}</span>
                            </td>
                            <td data-label="الحالة" class="px-5 py-3 text-right">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="o.is_active?'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300':'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ o.is_active?'نشط':'معطل' }}</span>
                                    <button type="button" @click="toggleBot(o)" :title="o.is_bot_visible?'اضغط لإخفائه عن البوت':'اضغط لإظهاره للبوت'" class="px-2.5 py-1 rounded-full text-xs font-bold cursor-pointer transition hover:opacity-80" :class="o.is_bot_visible?'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300':'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ o.is_bot_visible?'🤖 ظاهر للبوت':'مخفي عن البوت' }}</button>
                                </div>
                            </td>
                            <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                                <button v-if="can?.update" @click="openModal(o)" class="px-2 py-1 text-xs text-gold-700 hover:bg-gold-50 dark:hover:bg-gold-900/20 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                                <button v-if="can?.delete" @click="del(o)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg btn-mobile-sm">🗑️ حذف</button>
                            </td>
                        </tr>
                        <tr v-if="!offers.data?.length"><td colspan="6" class="px-5 py-12 text-center text-gray-400">لا توجد عروض</td></tr>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="offers.last_page > 1" class="flex justify-center gap-1 mt-4">
                <template v-for="link in offers.links" :key="link.label">
                    <a v-if="link.url" :href="link.url" class="px-3 py-2 rounded-lg text-sm" :class="link.active?'bg-gold-500 text-black font-bold':'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'" v-html="link.label"/>
                    <span v-else class="px-3 py-2 text-sm text-gray-400" v-html="link.label"/>
                </template>
            </div>
        </div>

        <!-- Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-3xl mx-4 p-6 max-h-[90vh] overflow-y-auto modal-responsive">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editItem?'تعديل العرض':'إضافة عرض جديد' }}</h3>
                    <button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button>
                </div>
                <form @submit.prevent="submit" class="space-y-5">

                    <!-- ===== Zone A : بيانات يراها العميل ===== -->
                    <section class="space-y-4">
                        <div class="flex items-center gap-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                            <span class="text-base">👁️</span>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">بيانات يراها العميل</h4>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <label for="f-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عنوان العرض *</label>
                                <input id="f-title" v-model="form.title" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                            </div>
                            <div>
                                <label for="f-category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التصنيف *</label>
                                <select id="f-category" v-model="form.category" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option value="">اختر التصنيف</option>
                                    <option v-for="c in categories" :key="c" :value="c">{{ catLabel(c) }}</option>
                                </select>
                                <p v-if="form.errors.category" class="mt-1 text-xs text-red-500">{{ form.errors.category }}</p>
                            </div>
                        </div>

                        <div>
                            <label for="f-desc" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">وصف العرض (يظهر للعميل)</label>
                            <textarea id="f-desc" v-model="form.description_client" rows="3" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                            <p v-if="form.errors.description_client" class="mt-1 text-xs text-red-500">{{ form.errors.description_client }}</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <label for="f-price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السعر (د.أ) *</label>
                                <input id="f-price" v-model="form.price_jod" type="number" step="0.001" min="0" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.price_jod" class="mt-1 text-xs text-red-500">{{ form.errors.price_jod }}</p>
                            </div>
                            <div>
                                <label for="f-price-per" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السعر محسوب</label>
                                <select id="f-price-per" v-model="form.price_per" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option value="person">للفرد</option>
                                    <option value="room">للغرفة</option>
                                    <option value="group">للمجموعة</option>
                                </select>
                                <p v-if="form.errors.price_per" class="mt-1 text-xs text-red-500">{{ form.errors.price_per }}</p>
                            </div>
                        </div>

                        <!-- includes / excludes -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">✅ العرض يشمل</label>
                                    <button type="button" @click="addRow('includes')" class="px-2 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-300">+ إضافة</button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(it, i) in form.includes" :key="'inc-'+i" class="flex items-center gap-2">
                                        <label class="sr-only" :for="'f-inc-'+i">بند يشمله العرض {{ i+1 }}</label>
                                        <input :id="'f-inc-'+i" v-model="form.includes[i]" type="text" placeholder="مثال: تذاكر الطيران" class="flex-1 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        <button type="button" @click="removeRow('includes', i)" :aria-label="'حذف البند '+(i+1)" class="px-2.5 py-2 rounded-xl text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">&times;</button>
                                    </div>
                                    <p v-if="!form.includes.length" class="text-xs text-gray-400">لا توجد بنود.</p>
                                </div>
                                <p v-if="form.errors.includes" class="mt-1 text-xs text-red-500">{{ form.errors.includes }}</p>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">❌ العرض لا يشمل</label>
                                    <button type="button" @click="addRow('excludes')" class="px-2 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-300">+ إضافة</button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(it, i) in form.excludes" :key="'exc-'+i" class="flex items-center gap-2">
                                        <label class="sr-only" :for="'f-exc-'+i">بند لا يشمله العرض {{ i+1 }}</label>
                                        <input :id="'f-exc-'+i" v-model="form.excludes[i]" type="text" placeholder="مثال: رسوم التأشيرة" class="flex-1 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        <button type="button" @click="removeRow('excludes', i)" :aria-label="'حذف البند '+(i+1)" class="px-2.5 py-2 rounded-xl text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">&times;</button>
                                    </div>
                                    <p v-if="!form.excludes.length" class="text-xs text-gray-400">لا توجد بنود.</p>
                                </div>
                                <p v-if="form.errors.excludes" class="mt-1 text-xs text-red-500">{{ form.errors.excludes }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <label for="f-dep" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تاريخ المغادرة</label>
                                <input id="f-dep" v-model="form.departure_date" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.departure_date" class="mt-1 text-xs text-red-500">{{ form.errors.departure_date }}</p>
                            </div>
                            <div>
                                <label for="f-ret" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تاريخ العودة</label>
                                <input id="f-ret" v-model="form.return_date" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.return_date" class="mt-1 text-xs text-red-500">{{ form.errors.return_date }}</p>
                            </div>
                            <div>
                                <label for="f-vfrom" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العرض صالح من</label>
                                <input id="f-vfrom" v-model="form.valid_from" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.valid_from" class="mt-1 text-xs text-red-500">{{ form.errors.valid_from }}</p>
                            </div>
                            <div>
                                <label for="f-vto" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العرض صالح حتى</label>
                                <input id="f-vto" v-model="form.valid_to" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.valid_to" class="mt-1 text-xs text-red-500">{{ form.errors.valid_to }}</p>
                            </div>
                            <div>
                                <label for="f-nights" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عدد الليالي</label>
                                <input id="f-nights" v-model="form.nights" type="number" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.nights" class="mt-1 text-xs text-red-500">{{ form.errors.nights }}</p>
                            </div>
                            <div>
                                <label for="f-hotel" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم الفندق</label>
                                <input id="f-hotel" v-model="form.hotel_name" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.hotel_name" class="mt-1 text-xs text-red-500">{{ form.errors.hotel_name }}</p>
                            </div>
                            <div>
                                <label for="f-rating" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تصنيف الفندق</label>
                                <select id="f-rating" v-model="form.hotel_rating" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option :value="null">— بدون —</option>
                                    <option v-for="n in 7" :key="n" :value="n">{{ n }} {{ stars(n) }}</option>
                                </select>
                                <p v-if="form.errors.hotel_rating" class="mt-1 text-xs text-red-500">{{ form.errors.hotel_rating }}</p>
                            </div>
                            <div>
                                <label for="f-airline" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">شركة الطيران</label>
                                <input id="f-airline" v-model="form.airline" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.airline" class="mt-1 text-xs text-red-500">{{ form.errors.airline }}</p>
                            </div>
                            <div>
                                <label for="f-agent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المورّد / الوكيل</label>
                                <select id="f-agent" v-model="form.agent_id" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option :value="null">— بدون —</option>
                                    <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                                </select>
                                <p v-if="form.errors.agent_id" class="mt-1 text-xs text-red-500">{{ form.errors.agent_id }}</p>
                            </div>
                            <div>
                                <label for="f-seats" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المقاعد المتاحة</label>
                                <input id="f-seats" v-model="form.available_seats" type="number" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.available_seats" class="mt-1 text-xs text-red-500">{{ form.errors.available_seats }}</p>
                            </div>
                            <div>
                                <label for="f-sort" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ترتيب العرض</label>
                                <input id="f-sort" v-model="form.sort_order" type="number" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p class="mt-1 text-xs text-gray-400">الأصغر يظهر أولاً.</p>
                                <p v-if="form.errors.sort_order" class="mt-1 text-xs text-red-500">{{ form.errors.sort_order }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div class="flex items-center">
                                <label for="f-active" class="flex items-center gap-2 cursor-pointer">
                                    <input id="f-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">نشط</span>
                                </label>
                            </div>
                            <div>
                                <label for="f-bot" class="flex items-center gap-2 cursor-pointer">
                                    <input id="f-bot" v-model="form.is_bot_visible" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">🤖 ظاهر للبوت</span>
                                </label>
                                <p class="mt-1 text-xs text-gray-400">لن يعرضه البوت حتى تفعّل هذا</p>
                            </div>
                        </div>
                    </section>

                    <!-- ===== Zone B : داخلي ===== -->
                    <section class="rounded-xl border-2 border-dashed p-4 space-y-4 border-red-200 dark:border-red-800 bg-red-50/40 dark:bg-red-900/10">
                        <div class="flex items-center gap-2">
                            <h4 class="text-sm font-bold text-red-700 dark:text-red-300">🔒 داخلي — لا يراه العميل ولا البوت</h4>
                        </div>
                        <p class="text-xs font-medium text-red-600 dark:text-red-400">⚠️ هذه الحقول لا تُرسل للبوت إطلاقاً ولا تظهر للعميل.</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <label for="f-cost" class="block text-sm font-medium text-red-800 dark:text-red-200 mb-1">الكلفة (د.أ)</label>
                                <input id="f-cost" v-model="form.cost_jod" type="number" step="0.001" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-red-200 dark:border-red-800 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-red-400 focus:outline-none"/>
                                <p v-if="form.errors.cost_jod" class="mt-1 text-xs text-red-500">{{ form.errors.cost_jod }}</p>
                            </div>
                            <div class="flex items-end">
                                <div class="w-full px-4 py-2.5 rounded-xl border border-red-200 dark:border-red-800 bg-white/70 dark:bg-gray-800/60 text-sm">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">هامش الربح:</span>
                                    <span class="font-bold font-mono tabular-nums ms-2" :class="margin >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" dir="ltr">{{ money(margin) }} JOD</span>
                                    <span class="block text-[11px] text-gray-400 font-mono tabular-nums" dir="ltr">{{ money(form.price_jod) }} − {{ money(form.cost_jod) }}</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label for="f-notes" class="block text-sm font-medium text-red-800 dark:text-red-200 mb-1">ملاحظات داخلية</label>
                            <textarea id="f-notes" v-model="form.notes_internal" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-red-200 dark:border-red-800 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-red-400 focus:outline-none resize-none"></textarea>
                            <p v-if="form.errors.notes_internal" class="mt-1 text-xs text-red-500">{{ form.errors.notes_internal }}</p>
                        </div>
                    </section>

                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ editItem?'💾 تحديث':'✅ إضافة' }}</button>
                        <button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">هل أنت متأكد من حذف العرض <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.title }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="confirmDelete" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600 shadow-md">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
    title: String,
    offers: Object,
    filters: Object,
    agents: Array,
    categories: Array,
    can: Object,
});

const CAT_LABELS = {
    package: 'باقة',
    umrah: 'عمرة',
    hajj: 'حج',
    flight: 'تذاكر طيران',
    visa: 'تأشيرات',
    hotel: 'فنادق',
    transport: 'نقل',
    tour: 'رحلات سياحية',
};
const CAT_CHIPS = {
    package: 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200',
    umrah: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    hajj: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
    flight: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    visa: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    hotel: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    transport: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    tour: 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300',
};
const PER_LABELS = { person: 'للفرد', room: 'للغرفة', group: 'للمجموعة' };

const catLabel = (c) => CAT_LABELS[c] || c || '—';
const catChip = (c) => CAT_CHIPS[c] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
const perLabel = (p) => PER_LABELS[p] || '';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const stars = (n) => '⭐'.repeat(Math.max(0, Math.min(7, Number(n) || 0)));
const d = (v) => (v ? String(v).slice(0, 10) : '—');
const dateVal = (v) => (v ? String(v).slice(0, 10) : '');
const toArr = (v) => {
    if (Array.isArray(v)) return v.map((x) => String(x ?? ''));
    if (typeof v === 'string' && v.trim()) {
        try {
            const p = JSON.parse(v);
            return Array.isArray(p) ? p.map((x) => String(x ?? '')) : [v];
        } catch (e) {
            return [v];
        }
    }
    return [];
};

const search = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category ?? '');
const showForm = ref(false);
const editItem = ref(null);
const deleteTarget = ref(null);
let t = null;

const form = useForm({
    title: '',
    category: '',
    description_client: '',
    price_jod: '',
    price_per: 'person',
    includes: [],
    excludes: [],
    departure_date: '',
    return_date: '',
    valid_from: '',
    valid_to: '',
    nights: '',
    hotel_name: '',
    hotel_rating: null,
    airline: '',
    agent_id: null,
    available_seats: '',
    sort_order: 0,
    is_active: true,
    is_bot_visible: false,
    cost_jod: '',
    notes_internal: '',
});

const margin = computed(() => Number(form.price_jod || 0) - Number(form.cost_jod || 0));

const addRow = (key) => { form[key] = [...form[key], '']; };
const removeRow = (key, i) => { form[key] = form[key].filter((_, idx) => idx !== i); };

const openModal = (o) => {
    editItem.value = o;
    form.title = o?.title || '';
    form.category = o?.category || '';
    form.description_client = o?.description_client || '';
    form.price_jod = o?.price_jod ?? '';
    form.price_per = o?.price_per || 'person';
    form.includes = toArr(o?.includes);
    form.excludes = toArr(o?.excludes);
    form.departure_date = dateVal(o?.departure_date);
    form.return_date = dateVal(o?.return_date);
    form.valid_from = dateVal(o?.valid_from);
    form.valid_to = dateVal(o?.valid_to);
    form.nights = o?.nights ?? '';
    form.hotel_name = o?.hotel_name || '';
    form.hotel_rating = o?.hotel_rating ?? null;
    form.airline = o?.airline || '';
    form.agent_id = o?.agent_id ?? null;
    form.available_seats = o?.available_seats ?? '';
    form.sort_order = o?.sort_order ?? 0;
    form.is_active = o?.is_active ?? true;
    form.is_bot_visible = o?.is_bot_visible ?? false;
    form.cost_jod = o?.cost_jod ?? '';
    form.notes_internal = o?.notes_internal || '';
    form.clearErrors();
    showForm.value = true;
};

const submit = () => {
    form.includes = form.includes.map((x) => String(x || '').trim()).filter((x) => x !== '');
    form.excludes = form.excludes.map((x) => String(x || '').trim()).filter((x) => x !== '');
    const o = {
        onSuccess: () => { showForm.value = false; form.reset(); form.clearErrors(); editItem.value = null; },
        preserveScroll: true,
        preserveState: false,
    };
    editItem.value ? form.put('/offers/' + editItem.value.id, o) : form.post('/offers', o);
};

const toggleBot = (o) => router.post('/offers/' + o.id + '/toggle-bot', {}, { preserveScroll: true, preserveState: false });

const filterParams = () => ({
    search: search.value || undefined,
    category: categoryFilter.value === '' || categoryFilter.value === null ? undefined : categoryFilter.value,
});
const applyFilters = () => router.get('/offers', filterParams(), { preserveState: true, replace: true });
const debounceSearch = () => { clearTimeout(t); t = setTimeout(applyFilters, 400); };

const del = (o) => { deleteTarget.value = o; };
const confirmDelete = () => {
    if (deleteTarget.value) {
        router.delete('/offers/' + deleteTarget.value.id, {
            preserveScroll: true,
            onSuccess: () => { deleteTarget.value = null; },
        });
    }
};
</script>
