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
                        <option v-for="c in categories" :key="c" :value="c">{{ catLabel(c) }}</option>
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
                        <th class="px-5 py-3 text-right font-bold">الفنادق</th>
                        <th class="px-5 py-3 text-right font-bold">يبدأ من</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الليالي</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الصلاحية</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                        <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="o in offers.data" :key="o.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="العنوان" class="px-5 py-3 text-right">
                                <div class="font-medium text-gray-800 dark:text-gray-100">{{ o.title }}</div>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-bold" :class="catChip(o.category)">{{ catLabel(o.category) }}</span>
                            </td>
                            <td data-label="الفنادق" class="px-5 py-3 text-right">
                                <template v-if="Number(o.hotels_count) > 0">
                                    <div class="text-xs font-bold text-gray-700 dark:text-gray-200 tabular-nums">🏨 {{ o.hotels_count }} فندق</div>
                                    <div class="text-[11px] leading-4 text-gray-500 dark:text-gray-400 max-w-[220px] truncate" :title="hotelNames(o)">{{ hotelNames(o) }}</div>
                                </template>
                                <span v-else class="text-xs font-bold text-amber-600 dark:text-amber-400">— لا فنادق</span>
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
                            <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                                <button v-if="can?.update" @click="openModal(o)" class="px-2 py-1 text-xs text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                                <button v-if="can?.delete" @click="del(o)" class="px-2 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg btn-mobile-sm">🗑️ حذف</button>
                            </td>
                        </tr>
                        <tr v-if="!offers.data?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا توجد عروض</td></tr>
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
                                <label for="f-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عنوان العرض *</label>
                                <input id="f-title" v-model="form.title" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                            </div>
                            <div>
                                <label for="f-category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التصنيف *</label>
                                <select id="f-category" v-model="form.category" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
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
                                <label for="f-nights" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عدد الليالي</label>
                                <input id="f-nights" v-model="form.nights" type="number" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.nights" class="mt-1 text-xs text-red-500">{{ form.errors.nights }}</p>
                            </div>
                            <div>
                                <label for="f-airline" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">شركة الطيران</label>
                                <input id="f-airline" v-model="form.airline" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.airline" class="mt-1 text-xs text-red-500">{{ form.errors.airline }}</p>
                            </div>
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

                    <!-- ===== Section 2 : الفنادق والأسعار ===== -->
                    <section class="space-y-4">
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

                        <p class="text-xs text-gray-500 dark:text-gray-400">السعر دائماً للفرد الواحد ويختلف حسب سعة الغرفة والفندق.</p>

                        <p v-if="typeof form.errors.hotels === 'string' && form.errors.hotels" class="text-xs text-red-500">{{ form.errors.hotels }}</p>

                        <!-- Hotel cards -->
                        <div v-if="form.hotels.length" class="space-y-4">
                            <div v-for="(h, hi) in form.hotels" :key="'hotel-'+hi" class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                                <!-- card header -->
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h5 class="text-sm font-bold text-gray-800 dark:text-gray-100">فندق {{ hi + 1 }}</h5>
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="moveHotel(hi, -1)" :disabled="hi === 0" :aria-label="'تحريك الفندق '+(hi+1)+' للأعلى'" title="تحريك للأعلى" class="px-2 py-1 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">▲</button>
                                        <button type="button" @click="moveHotel(hi, 1)" :disabled="hi === form.hotels.length - 1" :aria-label="'تحريك الفندق '+(hi+1)+' للأسفل'" title="تحريك للأسفل" class="px-2 py-1 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">▼</button>
                                        <button type="button" @click="removeHotel(hi)" :aria-label="'حذف الفندق '+(hi+1)" title="حذف الفندق" class="px-2 py-1 rounded-lg text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">&times;</button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mobile-form-grid">
                                    <div>
                                        <label :for="'f-h-name-'+hi" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم الفندق *</label>
                                        <input :id="'f-h-name-'+hi" v-model="h.name" required placeholder="مثال: فندق دار التوحيد" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        <p v-if="form.errors['hotels.'+hi+'.name']" class="mt-1 text-xs text-red-500">{{ form.errors['hotels.'+hi+'.name'] }}</p>
                                    </div>
                                    <div>
                                        <label :for="'f-h-rating-'+hi" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تصنيف الفندق</label>
                                        <select :id="'f-h-rating-'+hi" v-model="h.rating" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                            <option :value="null">— بدون —</option>
                                            <option v-for="n in 7" :key="n" :value="n">{{ stars(n) }} {{ n }}</option>
                                        </select>
                                        <p v-if="form.errors['hotels.'+hi+'.rating']" class="mt-1 text-xs text-red-500">{{ form.errors['hotels.'+hi+'.rating'] }}</p>
                                    </div>
                                </div>

                                <!-- prices per room capacity -->
                                <div>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div v-for="(rLabel, rKey) in roomTypes" :key="'p-'+hi+'-'+rKey">
                                            <label :for="'f-h-'+hi+'-'+rKey" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ rLabel }}</label>
                                            <input :id="'f-h-'+hi+'-'+rKey" v-model="h.prices[rKey]" type="number" step="0.001" min="0" placeholder="—" dir="ltr" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm font-mono tabular-nums focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                            <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">د.أ / للفرد</p>
                                            <p v-if="form.errors['hotels.'+hi+'.prices.'+rKey]" class="mt-1 text-xs text-red-500">{{ form.errors['hotels.'+hi+'.prices.'+rKey] }}</p>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">اترك الخانة فارغة إذا كان هذا النوع غير متاح في هذا الفندق</p>
                                    <p v-if="form.errors['hotels.'+hi+'.prices']" class="mt-1 text-xs text-red-500">{{ form.errors['hotels.'+hi+'.prices'] }}</p>
                                </div>

                                <div>
                                    <label :for="'f-h-inc-'+hi" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ما يشمله سعر هذا الفندق</label>
                                    <textarea :id="'f-h-inc-'+hi" v-model="h.includes_note" rows="2" placeholder="مثال: يشمل الإفطار يومياً" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                                    <p v-if="form.errors['hotels.'+hi+'.includes_note']" class="mt-1 text-xs text-red-500">{{ form.errors['hotels.'+hi+'.includes_note'] }}</p>
                                </div>

                                <p class="text-xs font-bold" :class="hotelMin(h) !== null ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">
                                    <template v-if="hotelMin(h) !== null">أقل سعر: <span dir="ltr" class="font-mono tabular-nums">{{ money(hotelMin(h)) }}</span> د.أ للفرد</template>
                                    <template v-else>لم تُدخل أسعار بعد</template>
                                </p>
                            </div>

                            <button type="button" @click="addHotel" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50">+ إضافة فندق</button>
                        </div>

                        <!-- Empty state -->
                        <div v-else class="rounded-xl border-2 border-dashed p-6 text-center space-y-3 border-gray-300 dark:border-gray-600 bg-gray-50/60 dark:bg-gray-800/30">
                            <p class="text-sm text-gray-500 dark:text-gray-400">لم تُضف فنادق بعد — العرض لن يظهر للبوت بلا فندق واحد على الأقل بسعر</p>
                            <button type="button" @click="addHotel" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50">+ إضافة فندق</button>
                        </div>
                    </section>

                    <!-- ===== Section 3 : includes / excludes ===== -->
                    <section class="space-y-3">
                        <div class="pb-2 border-b border-gray-200 dark:border-gray-700">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">✅ العرض يشمل / ❌ العرض لا يشمل</h4>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">هذه البنود تنطبق على العرض بالكامل، وليست خاصة بفندق معيّن.</p>
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
    categories: Array,
    roomTypes: Object,
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

const catLabel = (c) => CAT_LABELS[c] || c || '—';
const catChip = (c) => CAT_CHIPS[c] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const stars = (n) => '⭐'.repeat(Math.max(0, Math.min(7, Number(n) || 0)));
const d = (v) => (v ? String(v).slice(0, 10) : '—');
const dateVal = (v) => (v ? String(v).slice(0, 10) : '');
const hasPrice = (v) => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
const hotelNames = (o) => (o.hotels || []).map((h) => h?.name).filter(Boolean).join('، ') || '—';

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
const blankHotel = () => ({ name: '', rating: null, includes_note: '', prices: blankPrices() });

// min of the filled prices of one hotel row (null when nothing usable)
const hotelMin = (h) => {
    const vals = roomKeys.value
        .map((k) => h?.prices?.[k])
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
    description_client: '',
    nights: null,
    airline: '',
    valid_from: '',
    valid_to: '',
    includes: [],
    excludes: [],
    is_active: true,
    is_bot_visible: false,
    sort_order: 0,
    hotels: [],
});

const formMinPrice = computed(() => {
    const mins = form.hotels.map((h) => hotelMin(h)).filter((v) => v !== null);
    return mins.length ? Math.min(...mins) : null;
});

const addHotel = () => { form.hotels = [...form.hotels, blankHotel()]; };
const removeHotel = (i) => { form.hotels = form.hotels.filter((_, idx) => idx !== i); };
const moveHotel = (i, dir) => {
    const j = i + dir;
    if (j < 0 || j >= form.hotels.length) return;
    const arr = [...form.hotels];
    const tmp = arr[i];
    arr[i] = arr[j];
    arr[j] = tmp;
    form.hotels = arr;
};

const addRow = (key) => { form[key] = [...form[key], '']; };
const removeRow = (key, i) => { form[key] = form[key].filter((_, idx) => idx !== i); };

const openModal = (o) => {
    editItem.value = o;
    form.title = o?.title || '';
    form.category = o?.category || 'package';
    form.description_client = o?.description_client || '';
    form.nights = o?.nights ?? null;
    form.airline = o?.airline || '';
    form.valid_from = dateVal(o?.valid_from);
    form.valid_to = dateVal(o?.valid_to);
    form.includes = toArr(o?.includes);
    form.excludes = toArr(o?.excludes);
    form.is_active = o?.is_active ?? true;
    form.is_bot_visible = o?.is_bot_visible ?? false;
    form.sort_order = o?.sort_order ?? 0;
    form.hotels = (o?.hotels || []).map((h) => {
        const prices = blankPrices();
        roomKeys.value.forEach((k) => {
            const v = h?.prices?.[k];
            prices[k] = v === null || v === undefined || String(v).trim() === '' ? null : v;
        });
        return {
            name: h?.name || '',
            rating: h?.rating ?? null,
            includes_note: h?.includes_note || '',
            prices,
        };
    });
    form.clearErrors();
    showForm.value = true;
};

const submit = () => {
    form.includes = form.includes.map((x) => String(x || '').trim()).filter((x) => x !== '');
    form.excludes = form.excludes.map((x) => String(x || '').trim()).filter((x) => x !== '');
    form.hotels = form.hotels.map((h, i) => {
        const prices = {};
        roomKeys.value.forEach((k) => {
            const v = h?.prices?.[k];
            prices[k] = v === null || v === undefined || String(v).trim() === '' ? null : Number(v);
        });
        return {
            name: String(h?.name || '').trim(),
            rating: h?.rating === '' || h?.rating === undefined ? null : h?.rating,
            includes_note: String(h?.includes_note || '').trim(),
            prices,
            sort_order: i,
        };
    });

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
