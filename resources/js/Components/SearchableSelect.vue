<template>
  <div class="searchable-select relative" ref="wrapper">
    <!-- الحقل المعروض -->
    <button
      type="button"
      @click="toggle"
      class="w-full px-4 py-2.5 rounded-xl border text-sm text-right flex items-center justify-between gap-2 transition-all"
      :class="[
        isOpen ? 'border-amber-400 ring-2 ring-amber-100' : 'border-gray-200',
        'bg-white hover:border-gray-300 focus:outline-none'
      ]"
    >
      <span :class="selectedLabel ? 'text-gray-900' : 'text-gray-400'">
        {{ selectedLabel || placeholder }}
      </span>
      <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform" :class="{ 'rotate-180': isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
      </svg>
    </button>

    <!-- القائمة المنسدلة -->
    <Teleport to="body">
      <div
        v-if="isOpen"
        ref="dropdownRef"
        class="bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden"
        :style="dropdownStyle"
      >
        <!-- حقل البحث -->
        <div class="p-2 border-b border-gray-100">
          <div class="relative">
            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input
              ref="searchInput"
              v-model="search"
              type="text"
              :placeholder="searchPlaceholder"
              class="w-full pr-9 pl-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-100"
              @keydown.escape="close"
              @keydown.enter.prevent="selectHighlighted"
              @keydown.down.prevent="highlightNext"
              @keydown.up.prevent="highlightPrev"
            />
          </div>
        </div>

        <!-- قائمة الخيارات -->
        <ul class="max-h-48 overflow-y-auto py-1" ref="listRef">
          <li
            v-if="filteredOptions.length === 0"
            class="px-4 py-3 text-sm text-gray-400 text-center"
          >
            لا توجد نتائج
          </li>
          <li
            v-for="(option, i) in filteredOptions"
            :key="option.value"
            @click="select(option)"
            @mouseenter="highlightedIndex = i"
            class="px-4 py-2.5 text-sm cursor-pointer transition-colors flex items-center justify-between"
            :class="[
              highlightedIndex === i ? 'bg-amber-50 text-amber-900' : 'text-gray-700 hover:bg-gray-50',
              modelValue === option.value ? 'font-semibold' : ''
            ]"
          >
            <span>{{ option.label }}</span>
            <svg v-if="modelValue === option.value" class="w-4 h-4 text-amber-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
            </svg>
          </li>
        </ul>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },
  placeholder: { type: String, default: 'اختر...' },
  searchPlaceholder: { type: String, default: 'ابحث...' },
  dropUp: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const search = ref('');
const highlightedIndex = ref(0);
const wrapper = ref(null);
const searchInput = ref(null);
const listRef = ref(null);
const dropdownRef = ref(null);
const dropdownPos = ref({ top: 0, left: 0, width: 0, openUp: false });

// حساب موقع القائمة
const dropdownStyle = computed(() => ({
  position: 'fixed',
  zIndex: 99999,
  width: dropdownPos.value.width + 'px',
  left: dropdownPos.value.left + 'px',
  ...(dropdownPos.value.openUp
    ? { bottom: (window.innerHeight - dropdownPos.value.top) + 'px' }
    : { top: dropdownPos.value.top + 'px' }),
}));

function calcPosition() {
  if (!wrapper.value) return;
  const rect = wrapper.value.getBoundingClientRect();
  const spaceBelow = window.innerHeight - rect.bottom;
  const openUp = props.dropUp || spaceBelow < 250;
  dropdownPos.value = {
    top: openUp ? rect.top : rect.bottom + 4,
    left: rect.left,
    width: rect.width,
    openUp,
  };
}

// الخيارات المفلترة
const filteredOptions = computed(() => {
  if (!search.value) return props.options;
  const q = search.value.toLowerCase();
  return props.options.filter(o => o.label.toLowerCase().includes(q));
});

// النص المعروض للخيار المحدد
const selectedLabel = computed(() => {
  const found = props.options.find(o => o.value === props.modelValue);
  return found ? found.label : '';
});

function toggle() {
  isOpen.value ? close() : open();
}

function open() {
  calcPosition();
  isOpen.value = true;
  search.value = '';
  highlightedIndex.value = 0;
  nextTick(() => searchInput.value?.focus());
}

function close() {
  isOpen.value = false;
}

function select(option) {
  emit('update:modelValue', option.value);
  emit('change', option.value);
  close();
}

function selectHighlighted() {
  if (filteredOptions.value.length > 0) {
    select(filteredOptions.value[highlightedIndex.value]);
  }
}

function highlightNext() {
  if (highlightedIndex.value < filteredOptions.value.length - 1) {
    highlightedIndex.value++;
    scrollToHighlighted();
  }
}

function highlightPrev() {
  if (highlightedIndex.value > 0) {
    highlightedIndex.value--;
    scrollToHighlighted();
  }
}

function scrollToHighlighted() {
  nextTick(() => {
    const list = listRef.value;
    if (!list) return;
    const item = list.children[highlightedIndex.value];
    if (item) item.scrollIntoView({ block: 'nearest' });
  });
}

// إغلاق عند النقر خارج المكوّن
function handleClickOutside(e) {
  if (wrapper.value && !wrapper.value.contains(e.target) && (!dropdownRef.value || !dropdownRef.value.contains(e.target))) {
    close();
  }
}

onMounted(() => document.addEventListener('mousedown', handleClickOutside));
onUnmounted(() => document.removeEventListener('mousedown', handleClickOutside));

watch(search, () => { highlightedIndex.value = 0; });
</script>
