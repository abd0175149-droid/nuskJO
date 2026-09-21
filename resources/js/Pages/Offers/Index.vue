<template>
    <AppLayout>
        <template #header>العروض والباقات</template>

        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>

            <!-- Filter bar -->
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <label class="sr-only" for="offers-search">بحث</label>
                    <input id="offers-search" v-model="search" type="text" placeholder="بحث بعنوان العرض أو الفندق..." class="w-72 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <label class="sr-only" for="offers-category">التصنيف</label>
                    <select id="offers-category" v-model="categoryFilter" @change="applyFilters" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500">
                        <option value="">🗂️ كل التصنيفات</option>
                        <option v-for="(cLabel, cKey) in categories" :key="cKey" :value="cKey">{{ cLabel }}</option>
                    </select>
                </div>
                <button v-if="can?.create" @click="openModal(null)" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:shadow-gold-500/25 w-full sm:w-auto">+ إضافة عرض</button>
            </div>

            <!-- Bot info banner -->
            <div class="p-4 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">
                🤖 العروض التي تفعّل لها «ظاهر للبوت» هي وحدها التي يستخدمها بوت الواتساب في الردّ على العملاء. السعر دائماً للفرد الواحد ويختلف حسب الفندق وسعة الغرفة.
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-5 py-3 text-right font-bold">العنوان</th>
                        <th class="px-5 py-3 text-right font-bold">المحتوى</th>
                        <th class="px-5 py-3 text-right font-bold">يبدأ من</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الليالي</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الصلاحية</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                        <th class="px-5 py-3 text-right font-bold">البطاقة</th>
                        <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="o in offers.data" :key="o.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="العنوان" class="px-5 py-3 text-right">
                                <div class="font-medium text-gray-800 dark:text-gray-100">{{ o.title }}</div>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-bold" :class="catChip(o.category)">{{ o.category_label || catLabel(o.category) }}</span>
                            </td>
                            <td data-label="المحتوى" class="px-5 py-3 text-right">
                                <span v-if="o.is_visa" class="inline-block px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">تأشيرة</span>
                                <template v-else-if="Number(o.options_count) > 0">
                                    <div class="text-xs font-bold text-gray-700 dark:text-gray-200 tabular-nums">🏨 {{ o.options_count }} خيار</div>
                                    <div class="text-[11px] leading-4 text-gray-500 dark:text-gray-400 max-w-[220px] truncate" :title="optionLabels(o)">{{ optionLabels(o) }}</div>
                                </template>
                                <span v-else class="text-xs font-bold text-amber-600 dark:text-amber-400">— لا خيارات</span>
                            </td>
                            <td data-label="يبدأ من" class="px-5 py-3 text-right whitespace-nowrap">
                                <template v-if="hasPrice(o.price_from)">
                                    <span class="font-bold font-mono text-xs tabular-nums text-gray-800 dark:text-gray-100" dir="ltr">{{ money(o.price_from) }}</span>
                                    <span class="text-xs text-gray-400"> د.أ / للفرد</span>
                                </template>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td data-label="الليالي" class="px-5 py-3 text-right text-xs hide-mobile tabular-nums" :class="o.nights ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400'">{{ o.nights ? o.nights + ' ليلة' : '—' }}</td>
                            <td data-label="الصلاحية" class="px-5 py-3 text-right text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap hide-mobile">
                                <span v-if="o.valid_from || o.valid_to" dir="ltr" class="inline-block font-mono tabular-nums">{{ d(o.valid_from) }} → {{ d(o.valid_to) }}</span>
                                <span v-else class="text-gray-400">مفتوح</span>
                            </td>
                            <td data-label="الحالة" class="px-5 py-3 text-right">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="o.is_active?'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300':'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ o.is_active?'نشط':'معطل' }}</span>
                                    <button type="button" @click="toggleBot(o)" :title="o.is_bot_visible?'اضغط لإخفائه عن البوت':'اضغط لإظهاره للبوت'" class="px-2.5 py-1 rounded-full text-xs font-bold cursor-pointer transition hover:opacity-80" :class="o.is_bot_visible?'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300':'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ o.is_bot_visible?'🤖 ظاهر للبوت':'مخفي عن البوت' }}</button>
                                </div>
                            </td>
                            <td data-label="البطاقة" class="px-5 py-3 text-right whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="cardChip(o).cls">{{ cardChip(o).text }}</span>
                            </td>
                            <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                                <button v-if="can?.update" @click="openModal(o)" class="px-2 py-1 text-xs text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                                <button v-if="can?.delete" @click="del(o)" class="px-2 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg btn-mobile-sm">🗑️ حذف</button>
                            </td>
                        </tr>
                        <tr v-if="!offers.data?.length"><td colspan="8" class="px-5 py-12 text-center text-gray-400">لا توجد عروض</td></tr>
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
                    <button @click="showForm=false" aria-label="إغلاق" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button>
                </div>

                <form @submit.prevent="submit" class="space-y-6">

                    <!-- ===== Section 1 : بيانات العرض ===== -->
                    <section class="space-y-4">
                        <div class="flex items-center gap-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                            <span class="text-base">📋</span>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">بيانات العرض</h4>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <label for="f-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ isVisa ? 'مسمى التأشيرة *' : 'عنوان العرض *' }}</label>
                                <input id="f-title" v-model="form.title" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                            </div>
                            <div>
                                <label for="f-category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التصنيف *</label>
                                <select id="f-category" v-model="form.category" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option v-for="(cLabel, cKey) in categories" :key="cKey" :value="cKey">{{ cLabel }}</option>
                                </select>
                                <p v-if="form.errors.category" class="mt-1 text-xs text-red-500">{{ form.errors.category }}</p>
                            </div>
                        </div>

                        <div>
                            <label for="f-desc" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">وصف العرض (يظهر للعميل)</label>
                            <textarea id="f-desc" v-model="form.description_client" rows="3" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                            <p v-if="form.errors.description_client" class="mt-1 text-xs text-red-500">{{ form.errors.description_client }}</p>
                        </div>

                        <div>
                            <label for="f-notes-public" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ملاحظات تظهر للعميل</label>
                            <textarea id="f-notes-public" v-model="form.notes_public" rows="3" maxlength="1000" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">تظهر في أسفل بطاقة العرض — مثل: جواز سفر ساري المفعول لمدة 7 أشهر</p>
                            <p v-if="form.errors.notes_public" class="mt-1 text-xs text-red-500">{{ form.errors.notes_public }}</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <template v-if="!isVisa">
                                <div>
                                    <label for="f-nights" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عدد الليالي</label>
                                    <input id="f-nights" v-model="form.nights" type="number" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                    <p v-if="form.errors.nights" class="mt-1 text-xs text-red-500">{{ form.errors.nights }}</p>
                                </div>
                                <div>
                                    <label for="f-airline" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">شركة الطيران</label>
                                    <input id="f-airline" v-model="form.airline" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                    <p v-if="form.errors.airline" class="mt-1 text-xs text-red-500">{{ form.errors.airline }}</p>
                                </div>
                            </template>
                            <div>
                                <label for="f-vfrom" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العرض صالح من</label>
                                <input id="f-vfrom" v-model="form.valid_from" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.valid_from" class="mt-1 text-xs text-red-500">{{ form.errors.valid_from }}</p>
                            </div>
                            <div>
                                <label for="f-vto" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العرض صالح حتى</label>
                                <input id="f-vto" v-model="form.valid_to" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">اتركهما فارغين إذا كان العرض مفتوحاً.</p>
                                <p v-if="form.errors.valid_to" class="mt-1 text-xs text-red-500">{{ form.errors.valid_to }}</p>
                            </div>
                            <div>
                                <label for="f-sort" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ترتيب العرض</label>
                                <input id="f-sort" v-model="form.sort_order" type="number" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">الأصغر يظهر أولاً.</p>
                                <p v-if="form.errors.sort_order" class="mt-1 text-xs text-red-500">{{ form.errors.sort_order }}</p>
                            </div>
                            <div class="flex flex-col gap-3 justify-center">
                                <label for="f-active" class="flex items-center gap-2 cursor-pointer">
                                    <input id="f-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">نشط</span>
                                </label>
                                <div>
                                    <label for="f-bot" class="flex items-center gap-2 cursor-pointer">
                                        <input id="f-bot" v-model="form.is_bot_visible" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">🤖 ظاهر للبوت</span>
                                    </label>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">لن يعرضه البوت حتى تفعّل هذا</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ===== Section 2-أ : بيانات التأشيرة ===== -->
                    <section v-if="isVisa" class="space-y-4">
                        <div class="flex items-center gap-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                            <span class="text-base">🛂</span>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">بيانات التأشيرة</h4>
                        </div>

                        <div>
                            <label for="f-requirements" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الشروط والمتطلبات</label>
                            <textarea id="f-requirements" v-model="form.requirements" rows="4" placeholder="مثال: جواز سفر ساري 6 أشهر + صورة شخصية بخلفية بيضاء" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                            <p v-if="form.errors.requirements" class="mt-1 text-xs text-red-500">{{ form.errors.requirements }}</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="f-visa-validity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">صلاحية التأشيرة</label>
                                <input id="f-visa-validity" v-model="form.visa_validity" type="text" maxlength="60" placeholder="مثال: 3 أشهر" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.visa_validity" class="mt-1 text-xs text-red-500">{{ form.errors.visa_validity }}</p>
                            </div>
                            <div>
                                <label for="f-visa-entries" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عدد مرات الدخول</label>
                                <input id="f-visa-entries" v-model="form.visa_entries" type="text" maxlength="40" list="visa-entries-options" placeholder="مثال: دخول واحد" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <datalist id="visa-entries-options">
                                    <option v-for="e in VISA_ENTRY_OPTIONS" :key="'ent-'+e" :value="e"></option>
                                </datalist>
                                <p v-if="form.errors.visa_entries" class="mt-1 text-xs text-red-500">{{ form.errors.visa_entries }}</p>
                            </div>
                            <div>
                                <label for="f-visa-processing" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المدة اللازمة للإصدار</label>
                                <input id="f-visa-processing" v-model="form.visa_processing" type="text" maxlength="60" placeholder="مثال: 5 أيام عمل" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.visa_processing" class="mt-1 text-xs text-red-500">{{ form.errors.visa_processing }}</p>
                            </div>
                            <div>
                                <label for="f-price-person" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السعر للشخص الواحد</label>
                                <div class="flex items-center gap-2">
                                    <input id="f-price-person" v-model="form.price_per_person" type="number" step="0.001" min="0" placeholder="—" dir="ltr" class="flex-1 min-w-0 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">د.أ</span>
                                </div>
                                <p v-if="form.errors.price_per_person" class="mt-1 text-xs text-red-500">{{ form.errors.price_per_person }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- ===== Section 2-ب : الفنادق والأسعار ===== -->
                    <section v-else class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-2">
                                <span class="text-base">🏨</span>
                                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">الفنادق والأسعار</h4>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold tabular-nums" :class="formMinPrice !== null ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'">
                                <template v-if="formMinPrice !== null">يبدأ من <span dir="ltr" class="font-mono">{{ money(formMinPrice) }}</span> د.أ للفرد</template>
                                <template v-else>—</template>
                            </span>
                        </div>

                        <!-- route mode -->
                        <div v-if="showRouteMode" class="rounded-xl border p-3 space-y-2 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                            <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">المسار</span>
                            <div role="radiogroup" aria-label="المسار" class="flex flex-wrap gap-2">
                                <label v-for="(rmLabel, rmKey) in routeModes" :key="'rm-'+rmKey" :for="'f-route-'+rmKey" class="flex items-center gap-2 px-3 py-2 rounded-xl border text-sm cursor-pointer transition"
                                       :class="form.route_mode === rmKey ? 'border-gold-500 bg-gold-50 text-gold-800 font-bold dark:bg-gold-900/30 dark:text-gold-200' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:border-gold-400'">
                                    <input :id="'f-route-'+rmKey" type="radio" name="f-route-mode" :value="rmKey" :checked="form.route_mode === rmKey" @change="setRouteMode(rmKey)" class="w-4 h-4 text-gold-500"/>
                                    <span>{{ rmLabel }}</span>
                                </label>
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500">«مكة والمدينة» يجعل كل خيار يضمّ فندقين بسعر واحد لهما معاً.</p>
                            <p v-if="form.errors.route_mode" class="text-xs text-red-500">{{ form.errors.route_mode }}</p>
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">السعر دائماً للفرد الواحد ويختلف حسب سعة الغرفة والخيار.</p>

                        <p v-if="typeof form.errors.options === 'string' && form.errors.options" class="text-xs text-red-500">{{ form.errors.options }}</p>

                        <!-- Option cards -->
                        <div v-if="form.options.length" class="space-y-4">
                            <div v-for="(opt, oi) in form.options" :key="'opt-'+oi" class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                                <!-- card header -->
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h5 class="text-sm font-bold text-gray-800 dark:text-gray-100">الخيار {{ oi + 1 }}</h5>
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="moveOption(oi, -1)" :disabled="oi === 0" :aria-label="'تحريك الخيار '+(oi+1)+' للأعلى'" title="تحريك للأعلى" class="px-2 py-1 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">▲</button>
                                        <button type="button" @click="moveOption(oi, 1)" :disabled="oi === form.options.length - 1" :aria-label="'تحريك الخيار '+(oi+1)+' للأسفل'" title="تحريك للأسفل" class="px-2 py-1 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">▼</button>
                                        <button type="button" @click="removeOption(oi)" :aria-label="'حذف الخيار '+(oi+1)" title="حذف الخيار" class="px-2 py-1 rounded-lg text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">&times;</button>
                                    </div>
                                </div>

                                <p v-if="form.errors['options.'+oi+'.stays']" class="text-xs text-red-500">{{ form.errors['options.'+oi+'.stays'] }}</p>

                                <!-- stays of this option -->
                                <div class="space-y-3">
                                    <div v-for="(stay, si) in opt.stays" :key="'opt-'+oi+'-stay-'+si" class="space-y-3" :class="isMultiCity ? 'rounded-xl border p-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40' : ''">
                                        <h6 v-if="isMultiCity" class="text-xs font-bold text-gray-700 dark:text-gray-200">{{ cityHeading(si, stay) }}</h6>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mobile-form-grid">
                                            <div>
                                                <label :for="'f-o-'+oi+'-s-'+si+'-name'" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم الفندق *</label>
                                                <input :id="'f-o-'+oi+'-s-'+si+'-name'" v-model="stay.name" required placeholder="مثال: فندق دار التوحيد" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                                <p v-if="form.errors['options.'+oi+'.stays.'+si+'.name']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.stays.'+si+'.name'] }}</p>
                                            </div>
                                            <div>
                                                <label :for="'f-o-'+oi+'-s-'+si+'-rating'" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التصنيف</label>
                                                <div class="flex items-center gap-3 flex-wrap">
                                                    <select :id="'f-o-'+oi+'-s-'+si+'-rating'" v-model="stay.rating" class="flex-1 min-w-0 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                                        <option :value="null">— بدون —</option>
                                                        <option v-for="n in 7" :key="n" :value="n">{{ stars(n) }} {{ n }}</option>
                                                    </select>
                                                    <label :for="'f-o-'+oi+'-s-'+si+'-ratingplus'" class="flex items-center gap-1.5 shrink-0" :class="ratingLabel(stay) ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'">
                                                        <input :id="'f-o-'+oi+'-s-'+si+'-ratingplus'" v-model="stay.rating_plus" type="checkbox" :disabled="!ratingLabel(stay)" class="w-4 h-4 rounded text-gold-500 disabled:cursor-not-allowed"/>
                                                        <span class="text-sm text-gray-700 dark:text-gray-300">زائد (+)</span>
                                                    </label>
                                                    <span v-if="ratingLabel(stay)" class="shrink-0 text-xs text-gray-400 dark:text-gray-500">يظهر: <span dir="ltr" class="font-mono">{{ ratingLabel(stay) }}</span></span>
                                                </div>
                                                <p v-if="form.errors['options.'+oi+'.stays.'+si+'.rating']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.stays.'+si+'.rating'] }}</p>
                                                <p v-if="form.errors['options.'+oi+'.stays.'+si+'.rating_plus']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.stays.'+si+'.rating_plus'] }}</p>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3" :class="usesHaramDistance ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
                                            <div>
                                                <label :for="'f-o-'+oi+'-s-'+si+'-loc'" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموقع</label>
                                                <input :id="'f-o-'+oi+'-s-'+si+'-loc'" v-model="stay.location" type="text" maxlength="80" placeholder="مثال: التيسير" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                                <p v-if="form.errors['options.'+oi+'.stays.'+si+'.location']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.stays.'+si+'.location'] }}</p>
                                            </div>
                                            <div>
                                                <label :for="'f-o-'+oi+'-s-'+si+'-meals'" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الوجبات</label>
                                                <input :id="'f-o-'+oi+'-s-'+si+'-meals'" v-model="stay.meals" type="text" maxlength="60" :list="'meals-options-'+oi+'-'+si" placeholder="مثال: بدون / إفطار" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                                <datalist :id="'meals-options-'+oi+'-'+si">
                                                    <option v-for="m in MEAL_OPTIONS" :key="'meal-'+oi+'-'+si+'-'+m" :value="m"></option>
                                                </datalist>
                                                <p v-if="form.errors['options.'+oi+'.stays.'+si+'.meals']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.stays.'+si+'.meals'] }}</p>
                                            </div>
                                            <div v-if="usesHaramDistance">
                                                <label :for="'f-o-'+oi+'-s-'+si+'-dist'" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المسافة عن الحرم</label>
                                                <input :id="'f-o-'+oi+'-s-'+si+'-dist'" v-model="stay.distance_haram" type="text" maxlength="40" placeholder="مثال: 950 متر" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                                <p v-if="form.errors['options.'+oi+'.stays.'+si+'.distance_haram']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.stays.'+si+'.distance_haram'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- one price row for the whole option -->
                                <div>
                                    <p v-if="isMultiCity" class="mb-2 text-xs font-bold text-gray-600 dark:text-gray-300">السعر للفرد ويشمل الإقامة في الفندقين معاً.</p>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div v-for="(rLabel, rKey) in roomTypes" :key="'p-'+oi+'-'+rKey">
                                            <label :for="'f-o-'+oi+'-'+rKey" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ rLabel }}</label>
                                            <input :id="'f-o-'+oi+'-'+rKey" v-model="opt.prices[rKey]" type="number" step="0.001" min="0" placeholder="—" dir="ltr" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                            <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">د.أ / للفرد</p>
                                            <p v-if="form.errors['options.'+oi+'.prices.'+rKey]" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.prices.'+rKey] }}</p>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">اترك الخانة فارغة إذا كان هذا النوع غير متاح</p>
                                    <p v-if="form.errors['options.'+oi+'.prices']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.prices'] }}</p>
                                </div>

                                <div>
                                    <label :for="'f-o-inc-'+oi" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ما يشمله سعر هذا الخيار</label>
                                    <textarea :id="'f-o-inc-'+oi" v-model="opt.includes_note" rows="2" placeholder="مثال: يشمل الإفطار يومياً" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                                    <p v-if="form.errors['options.'+oi+'.includes_note']" class="mt-1 text-xs text-red-500">{{ form.errors['options.'+oi+'.includes_note'] }}</p>
                                </div>

                                <p class="text-xs font-bold" :class="optionMin(opt) !== null ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">
                                    <template v-if="optionMin(opt) !== null">أقل سعر: <span dir="ltr" class="font-mono tabular-nums">{{ money(optionMin(opt)) }}</span> د.أ للفرد</template>
                                    <template v-else>لم تُدخل أسعار بعد</template>
                                </p>
                            </div>

                            <button type="button" @click="addOption" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50">+ إضافة خيار</button>
                        </div>

                        <!-- Empty state -->
                        <div v-else class="rounded-xl border-2 border-dashed p-6 text-center space-y-3 border-gray-300 dark:border-gray-600 bg-gray-50/60 dark:bg-gray-800/30">
                            <p class="text-sm text-gray-500 dark:text-gray-400">لم تُضف خيارات بعد — العرض لن يظهر للبوت بلا خيار واحد على الأقل بسعر</p>
                            <button type="button" @click="addOption" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50">+ إضافة خيار</button>
                        </div>
                    </section>

                    <!-- ===== Section 3 : بطاقة العرض ===== -->
                    <section class="space-y-4">
                        <div class="flex items-center gap-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                            <span class="text-base">🖼️</span>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">بطاقة العرض</h4>
                        </div>

                        <p v-if="!editItem" class="text-xs text-gray-400 dark:text-gray-500">احفظ العرض أولاً ثم يمكنك توليد بطاقته.</p>

                        <template v-else>
                            <!-- notices -->
                            <div v-if="editItem.card_is_stale && editItem.card_url" class="p-3 rounded-xl border text-xs bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">
                                ⚠️ البطاقة أقدم من آخر تعديل — أعد توليدها
                            </div>
                            <div v-if="editItem.card_is_custom" class="space-y-1">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">بطاقة مرفوعة يدوياً</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">يتم إرسال البطاقة المرفوعة، ولا تُستخدم البطاقة المولّدة تلقائياً.</p>
                            </div>

                            <!-- thumbnail -->
                            <a v-if="editItem.card_url" :href="editItem.card_url" target="_blank" rel="noopener" class="block w-fit max-w-full rounded-xl border p-1 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 hover:border-gold-500">
                                <img :src="editItem.card_url" alt="معاينة بطاقة العرض" class="max-h-64 max-w-full rounded-lg"/>
                            </a>
                            <div v-else class="rounded-xl border-2 border-dashed p-6 text-center border-gray-300 dark:border-gray-600 bg-gray-50/60 dark:bg-gray-800/30">
                                <p class="text-sm text-gray-500 dark:text-gray-400">لم تُولَّد بطاقة بعد</p>
                            </div>

                            <!-- actions -->
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="generateCard" :disabled="cardBusy" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50 disabled:opacity-50 disabled:cursor-not-allowed">{{ cardBusy ? '...جارٍ التوليد' : '🔄 توليد البطاقة' }}</button>

                                <a :href="'/offers/'+editItem.id+'/card/preview'" target="_blank" rel="noopener" class="px-4 py-2 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700">👁️ معاينة القالب</a>

                                <button type="button" @click="cardFileInput?.click()" class="px-4 py-2 rounded-xl text-sm font-bold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100 dark:hover:bg-blue-900/50">⬆️ رفع بطاقة جاهزة</button>
                                <label class="sr-only" for="f-card-upload">رفع بطاقة جاهزة (PNG أو JPEG)</label>
                                <input id="f-card-upload" ref="cardFileInput" type="file" accept="image/png,image/jpeg" class="hidden" @change="onCardFile"/>

                                <button v-if="editItem.card_is_custom" type="button" @click="removeCustomCard" class="px-4 py-2 rounded-xl text-sm font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40">🗑️ إزالة المرفوعة</button>
                            </div>

                            <!-- hero image -->
                            <div class="rounded-xl border p-4 space-y-2 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="button" @click="heroFileInput?.click()" class="px-4 py-2 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700">🏞️ صورة الترويسة</button>
                                    <label class="sr-only" for="f-hero-upload">رفع صورة ترويسة البطاقة (PNG أو JPEG)</label>
                                    <input id="f-hero-upload" ref="heroFileInput" type="file" accept="image/png,image/jpeg" class="hidden" @change="onHeroFile"/>
                                    <img v-if="editItem.hero_url" :src="editItem.hero_url" alt="صورة ترويسة البطاقة" class="max-h-24 max-w-full rounded-lg border border-gray-200 dark:border-gray-700"/>
                                </div>
                                <p class="text-xs text-gray-400 dark:text-gray-500">إن لم تُرفع تُستخدم الترويسة الافتراضية من الإعدادات</p>
                            </div>
                        </template>
                    </section>

                    <!-- ===== Section 4 : includes / excludes ===== -->
                    <section class="space-y-3">
                        <div class="pb-2 border-b border-gray-200 dark:border-gray-700">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">✅ العرض يشمل / ❌ العرض لا يشمل</h4>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">هذه البنود تنطبق على العرض بالكامل، وليست خاصة بخيار معيّن.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">✅ العرض يشمل</span>
                                    <button type="button" @click="addRow('includes')" class="px-2 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-300 dark:hover:bg-green-900/50">+ إضافة</button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(it, i) in form.includes" :key="'inc-'+i" class="flex items-center gap-2">
                                        <label class="sr-only" :for="'f-inc-'+i">بند يشمله العرض {{ i+1 }}</label>
                                        <input :id="'f-inc-'+i" v-model="form.includes[i]" type="text" placeholder="مثال: تذاكر الطيران" class="flex-1 min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        <button type="button" @click="removeRow('includes', i)" :aria-label="'حذف البند '+(i+1)" class="px-2.5 py-2 rounded-xl text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">&times;</button>
                                    </div>
                                    <p v-if="!form.includes.length" class="text-xs text-gray-400 dark:text-gray-500">لا توجد بنود.</p>
                                </div>
                                <p v-if="form.errors.includes" class="mt-1 text-xs text-red-500">{{ form.errors.includes }}</p>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">❌ العرض لا يشمل</span>
                                    <button type="button" @click="addRow('excludes')" class="px-2 py-1 rounded-lg text-xs font-bold text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50">+ إضافة</button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(it, i) in form.excludes" :key="'exc-'+i" class="flex items-center gap-2">
                                        <label class="sr-only" :for="'f-exc-'+i">بند لا يشمله العرض {{ i+1 }}</label>
                                        <input :id="'f-exc-'+i" v-model="form.excludes[i]" type="text" placeholder="مثال: رسوم التأشيرة" class="flex-1 min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        <button type="button" @click="removeRow('excludes', i)" :aria-label="'حذف البند '+(i+1)" class="px-2.5 py-2 rounded-xl text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">&times;</button>
                                    </div>
                                    <p v-if="!form.excludes.length" class="text-xs text-gray-400 dark:text-gray-500">لا توجد بنود.</p>
                                </div>
                                <p v-if="form.errors.excludes" class="mt-1 text-xs text-red-500">{{ form.errors.excludes }}</p>
                            </div>
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
    categories: Object,   // {key: label}
    routeModes: Object,   // {makkah_only: 'مكة فقط', ...}
    routeCities: Object,  // {makkah_only: ['مكة'], ...}
    roomTypes: Object,    // {single: 'مفردة', ...}
    can: Object,
});

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

// التصنيفات المبنية على فنادق بمسار (مكة / مكة والمدينة)
const ROUTE_CATEGORIES = ['umrah', 'hajj'];
const MEAL_OPTIONS = ['بدون', 'إفطار', 'إفطار وعشاء', 'إفطار وغداء وعشاء'];
const VISA_ENTRY_OPTIONS = ['دخول واحد', 'متعددة الدخول'];
const CITY_ICONS = { 'مكة': '🕋', 'المدينة': '🕌' };

const catLabel = (c) => props.categories?.[c] || c || '—';
const catChip = (c) => CAT_CHIPS[c] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const stars = (n) => '⭐'.repeat(Math.max(0, Math.min(7, Number(n) || 0)));
const d = (v) => (v ? String(v).slice(0, 10) : '—');
const dateVal = (v) => (v ? String(v).slice(0, 10) : '');
const hasPrice = (v) => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
const optionLabels = (o) => (o.options || []).map((x) => x?.label).filter(Boolean).join('، ') || '—';

// حالة بطاقة العرض في الجدول
const cardChip = (o) => {
    if (!o?.card_url) return { text: '—', cls: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300' };
    if (o.card_is_stale) return { text: '⚠️ قديمة', cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' };
    return { text: '✅ جاهزة', cls: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' };
};

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

const roomKeys = computed(() => Object.keys(props.roomTypes || {}));

const blankPrices = () => {
    const p = {};
    roomKeys.value.forEach((k) => { p[k] = null; });
    return p;
};
const blankStay = (city = null) => ({
    city: city ?? null,
    name: '',
    rating: null,
    rating_plus: false,
    location: '',
    meals: '',
    distance_haram: '',
});
const blankOption = () => ({
    includes_note: '',
    prices: blankPrices(),
    stays: activeCities.value.length > 1 ? activeCities.value.map((c) => blankStay(c)) : [blankStay(null)],
});

// «4+» / «4» — what the card will print for this stay's rating
const ratingLabel = (s) => {
    if (s?.rating === null || s?.rating === undefined || s?.rating === '') return '';
    return String(s.rating) + (s?.rating_plus ? '+' : '');
};

// min of the filled prices of one option (null when nothing usable)
const optionMin = (opt) => {
    const vals = roomKeys.value
        .map((k) => opt?.prices?.[k])
        .filter((v) => v !== null && v !== undefined && String(v).trim() !== '')
        .map((v) => Number(v))
        .filter((v) => Number.isFinite(v) && v > 0);
    return vals.length ? Math.min(...vals) : null;
};

const search = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category ?? '');
const showForm = ref(false);
const editItem = ref(null);
const deleteTarget = ref(null);
let t = null;

const form = useForm({
    title: '',
    category: 'package',
    route_mode: null,
    description_client: '',
    notes_public: '',
    nights: null,
    airline: '',
    requirements: '',
    visa_validity: '',
    visa_entries: '',
    visa_processing: '',
    price_per_person: null,
    valid_from: '',
    valid_to: '',
    includes: [],
    excludes: [],
    is_active: true,
    is_bot_visible: false,
    sort_order: 0,
    options: [],
});

/* ===== شكل النموذج يتبع التصنيف ===== */
const isVisa = computed(() => form.category === 'visa');
const usesHaramDistance = computed(() => ROUTE_CATEGORIES.includes(form.category));
const showRouteMode = computed(() => ROUTE_CATEGORIES.includes(form.category));
const activeCities = computed(() => (showRouteMode.value && form.route_mode ? (props.routeCities?.[form.route_mode] || []) : []));
const isMultiCity = computed(() => activeCities.value.length > 1);

const cityHeading = (si, stay) => {
    const city = activeCities.value[si] || stay?.city || '';
    return city ? (CITY_ICONS[city] || '🏨') + ' فندق ' + city : '🏨 الفندق';
};

const formMinPrice = computed(() => {
    const mins = form.options.map((opt) => optionMin(opt)).filter((v) => v !== null);
    return mins.length ? Math.min(...mins) : null;
});

/* ===== الخيارات ===== */
const addOption = () => { form.options = [...form.options, blankOption()]; };
const removeOption = (i) => { form.options = form.options.filter((_, idx) => idx !== i); };
const moveOption = (i, dir) => {
    const j = i + dir;
    if (j < 0 || j >= form.options.length) return;
    const arr = [...form.options];
    const tmp = arr[i];
    arr[i] = arr[j];
    arr[j] = tmp;
    form.options = arr;
};

// يُعيد تشكيل الإقامات حسب مدن المسار دون إفقاد ما كتبه المستخدم
const reshapeStays = (cities) => {
    form.options = form.options.map((opt) => {
        const stays = [...(opt.stays || [])];
        if (!stays.length) stays.push(blankStay());

        if (cities.length > 1) {
            while (stays.length < cities.length) stays.push(blankStay(cities[stays.length]));
            stays.forEach((s, i) => { s.city = cities[i] ?? s.city ?? null; });
            return { ...opt, stays };
        }

        // مسار بمدينة واحدة (أو بلا مسار) — نُبقي أول إقامة فقط بلا مدينة
        return { ...opt, stays: [{ ...stays[0], city: null }] };
    });
};

const setRouteMode = (mode) => {
    if (form.route_mode === mode) return;
    form.route_mode = mode;
    reshapeStays(props.routeCities?.[mode] || []);
};

const addRow = (key) => { form[key] = [...form[key], '']; };
const removeRow = (key, i) => { form[key] = form[key].filter((_, idx) => idx !== i); };

const openModal = (o) => {
    editItem.value = o;
    form.title = o?.title || '';
    form.category = o?.category || 'package';
    form.route_mode = o?.route_mode || null;
    form.description_client = o?.description_client || '';
    form.notes_public = o?.notes_public || '';
    form.nights = o?.nights ?? null;
    form.airline = o?.airline || '';
    form.requirements = o?.requirements || '';
    form.visa_validity = o?.visa_validity || '';
    form.visa_entries = o?.visa_entries || '';
    form.visa_processing = o?.visa_processing || '';
    form.price_per_person = o?.price_per_person ?? null;
    form.valid_from = dateVal(o?.valid_from);
    form.valid_to = dateVal(o?.valid_to);
    form.includes = toArr(o?.includes);
    form.excludes = toArr(o?.excludes);
    form.is_active = o?.is_active ?? true;
    form.is_bot_visible = o?.is_bot_visible ?? false;
    form.sort_order = o?.sort_order ?? 0;

    const cities = form.route_mode ? (props.routeCities?.[form.route_mode] || []) : [];
    form.options = (o?.options || []).map((opt) => {
        const prices = blankPrices();
        roomKeys.value.forEach((k) => {
            const v = opt?.prices?.[k];
            prices[k] = v === null || v === undefined || String(v).trim() === '' ? null : v;
        });

        const stays = (opt?.stays || []).map((s) => ({
            city: s?.city ?? null,
            name: s?.name || '',
            rating: s?.rating ?? null,
            rating_plus: !!s?.rating_plus,
            location: s?.location || '',
            meals: s?.meals || '',
            distance_haram: s?.distance_haram || '',
        }));
        if (!stays.length) stays.push(blankStay(cities.length > 1 ? cities[0] : null));
        // مواءمة الإقامات مع مدن المسار المحفوظ — دون حذف إقامة محفوظة
        if (cities.length > 1) {
            while (stays.length < cities.length) stays.push(blankStay(cities[stays.length]));
            stays.forEach((s, i) => { if (cities[i]) s.city = cities[i]; });
        }

        return { includes_note: opt?.includes_note || '', prices, stays };
    });

    form.clearErrors();
    cardBusy.value = false;
    showForm.value = true;
};

const txt = (v) => {
    const s = String(v ?? '').trim();
    return s === '' ? null : s;
};
const num = (v) => {
    if (v === null || v === undefined || String(v).trim() === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
};

const submit = () => {
    form.title = String(form.title || '').trim();
    form.description_client = txt(form.description_client);
    form.notes_public = txt(form.notes_public);
    form.valid_from = txt(form.valid_from);
    form.valid_to = txt(form.valid_to);
    form.sort_order = num(form.sort_order);
    form.includes = form.includes.map((x) => String(x || '').trim()).filter((x) => x !== '');
    form.excludes = form.excludes.map((x) => String(x || '').trim()).filter((x) => x !== '');

    if (isVisa.value) {
        form.route_mode = null;
        form.nights = null;
        form.airline = null;
        form.requirements = txt(form.requirements);
        form.visa_validity = txt(form.visa_validity);
        form.visa_entries = txt(form.visa_entries);
        form.visa_processing = txt(form.visa_processing);
        form.price_per_person = num(form.price_per_person);
        form.options = [];
    } else {
        form.requirements = null;
        form.visa_validity = null;
        form.visa_entries = null;
        form.visa_processing = null;
        form.price_per_person = null;
        form.nights = num(form.nights);
        form.airline = txt(form.airline);
        form.route_mode = showRouteMode.value ? (form.route_mode || null) : null;

        form.options = form.options.map((opt, oi) => {
            const prices = {};
            roomKeys.value.forEach((k) => { prices[k] = num(opt?.prices?.[k]); });

            const stays = (opt?.stays || [])
                .filter((s) => String(s?.name || '').trim() !== '')
                .map((s, si) => ({
                    city: txt(s?.city),
                    name: String(s?.name || '').trim(),
                    rating: num(s?.rating),
                    rating_plus: !!s?.rating_plus,
                    location: txt(s?.location),
                    meals: txt(s?.meals),
                    distance_haram: usesHaramDistance.value ? txt(s?.distance_haram) : null,
                    sort_order: si,
                }));

            return {
                includes_note: txt(opt?.includes_note),
                prices,
                stays,
                sort_order: oi,
            };
        });
    }

    const o = {
        onSuccess: () => { showForm.value = false; form.reset(); form.clearErrors(); editItem.value = null; },
        preserveScroll: true,
        preserveState: false,
    };
    editItem.value ? form.put('/offers/' + editItem.value.id, o) : form.post('/offers', o);
};

const toggleBot = (o) => router.post('/offers/' + o.id + '/toggle-bot', {}, { preserveScroll: true, preserveState: false });

/* ===== بطاقة العرض (PNG) ===== */
const cardBusy = ref(false);
const cardFileInput = ref(null);
const heroFileInput = ref(null);

// المودال مفتوح على صف قديم — أعد ربطه بالصف المحدّث حتى تظهر البطاقة الجديدة
const syncEditItem = () => {
    if (!editItem.value) return;
    const fresh = (props.offers?.data || []).find((x) => x.id === editItem.value.id);
    if (fresh) editItem.value = fresh;
};

const generateCard = () => {
    if (!editItem.value || cardBusy.value) return;
    cardBusy.value = true;
    router.post('/offers/' + editItem.value.id + '/card', {}, {
        preserveScroll: true,
        onSuccess: syncEditItem,
        onFinish: () => { cardBusy.value = false; },
    });
};

const uploadImage = (e, key, url) => {
    const file = e?.target?.files?.[0];
    if (!file || !editItem.value) return;
    const fd = new FormData();
    fd.append(key, file);
    router.post('/offers/' + editItem.value.id + url, fd, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: syncEditItem,
    });
    e.target.value = '';
};

const onCardFile = (e) => uploadImage(e, 'card', '/card/upload');
const onHeroFile = (e) => uploadImage(e, 'hero', '/hero');

const removeCustomCard = () => {
    if (!editItem.value) return;
    router.delete('/offers/' + editItem.value.id + '/card', {
        preserveScroll: true,
        onSuccess: syncEditItem,
    });
};

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
