;(function () {
    const { ref, computed, onMounted, onUnmounted, watch } = Vue;

    window.AppSelect = {
        name: 'AppSelect',
        props: {
            modelValue: [String, Number],
            options: {
                type: Array,
                default: () => []
            },
            placeholder: {
                type: String,
                default: '请选择'
            },
            disabled: {
                type: Boolean,
                default: false
            }
        },
        emits: ['update:modelValue'],
        setup(props, { emit }) {
            const open = ref(false);
            const root = ref(null);
            const instanceId = `select-${Math.random().toString(36).slice(2)}`;

            const selectedOption = computed(() =>
                props.options.find((option) => String(option.value) === String(props.modelValue)) || null
            );

            const isSelected = (option) => String(option.value) === String(props.modelValue);

            const toggle = () => {
                if (props.disabled) return;
                if (!open.value) {
                    window.dispatchEvent(new CustomEvent('app-select-opened', {
                        detail: { id: instanceId }
                    }));
                }
                open.value = !open.value;
            };

            const selectOption = (option) => {
                emit('update:modelValue', option.value);
                open.value = false;
            };

            const handleClickOutside = (event) => {
                if (root.value && !root.value.contains(event.target)) {
                    open.value = false;
                }
            };

            const handleKeydown = (event) => {
                if (!root.value || !root.value.contains(document.activeElement)) return;
                if (event.key === 'Escape') {
                    open.value = false;
                }
            };

            const handleSelectOpened = (event) => {
                if (event?.detail?.id !== instanceId) {
                    open.value = false;
                }
            };

            onMounted(() => {
                document.addEventListener('click', handleClickOutside);
                document.addEventListener('keydown', handleKeydown);
                window.addEventListener('app-select-opened', handleSelectOpened);
            });

            onUnmounted(() => {
                document.removeEventListener('click', handleClickOutside);
                document.removeEventListener('keydown', handleKeydown);
                window.removeEventListener('app-select-opened', handleSelectOpened);
            });

            return {
                open,
                root,
                selectedOption,
                isSelected,
                toggle,
                selectOption
            };
        },
        template: `
            <div ref="root" class="select-shell" :class="{ open, disabled }">
                <button type="button" class="select-trigger" :disabled="disabled" @click.stop="toggle">
                    <span class="select-value" :class="{ 'is-placeholder': !selectedOption }">
                        {{ selectedOption ? selectedOption.label : placeholder }}
                    </span>
                    <span class="select-chevron">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </span>
                </button>

                <transition name="select-pop">
                    <div v-if="open" class="select-menu">
                        <button
                            v-for="option in options"
                            :key="String(option.value)"
                            type="button"
                            class="select-option"
                            :class="{ selected: isSelected(option) }"
                            @click="selectOption(option)"
                        >
                            <span>{{ option.label }}</span>
                            <span class="select-check">
                                <svg v-if="isSelected(option)" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="m5 13 4 4L19 7"/>
                                </svg>
                            </span>
                        </button>
                    </div>
                </transition>
            </div>
        `
    };

    window.TimePicker = {
        name: 'TimePicker',
        props: {
            modelValue: {
                type: String,
                default: ''
            },
            placeholder: {
                type: String,
                default: '请选择时间'
            },
            disabled: {
                type: Boolean,
                default: false
            }
        },
        emits: ['update:modelValue'],
        setup(props, { emit }) {
            const open = ref(false);
            const root = ref(null);
            const dropUp = ref(false);
            const tempHour = ref('00');
            const tempMinute = ref('00');
            const instanceId = `time-${Math.random().toString(36).slice(2)}`;
            const hours = Array.from({ length: 24 }, (_, hour) => String(hour).padStart(2, '0'));
            const minutes = Array.from({ length: 60 }, (_, minute) => String(minute).padStart(2, '0'));

            const syncTemp = () => {
                const match = String(props.modelValue || '').match(/^(\d{2}):(\d{2})$/);
                tempHour.value = match ? match[1] : '00';
                tempMinute.value = match ? match[2] : '00';
            };

            const toggle = () => {
                if (props.disabled) return;
                if (!open.value) {
                    syncTemp();
                    window.dispatchEvent(new CustomEvent('app-select-opened', {
                        detail: { id: instanceId }
                    }));
                    requestAnimationFrame(() => {
                        if (!root.value) return;
                        const rect = root.value.getBoundingClientRect();
                        dropUp.value = (window.innerHeight - rect.bottom) < 320 && rect.top > 260;
                    });
                }
                open.value = !open.value;
            };

            const selectHour = (hour) => {
                tempHour.value = hour;
            };

            const selectMinute = (minute) => {
                tempMinute.value = minute;
            };

            const confirm = () => {
                emit('update:modelValue', `${tempHour.value}:${tempMinute.value}`);
                open.value = false;
            };

            const setNow = () => {
                const now = new Date();
                tempHour.value = String(now.getHours()).padStart(2, '0');
                tempMinute.value = String(now.getMinutes()).padStart(2, '0');
                confirm();
            };

            const handleClickOutside = (event) => {
                if (root.value && !root.value.contains(event.target)) {
                    open.value = false;
                }
            };

            const handleKeydown = (event) => {
                if (!root.value || !root.value.contains(document.activeElement)) return;
                if (event.key === 'Escape') {
                    open.value = false;
                }
                if (event.key === 'Enter' && open.value) {
                    confirm();
                }
            };

            const handleSelectOpened = (event) => {
                if (event?.detail?.id !== instanceId) {
                    open.value = false;
                }
            };

            watch(() => props.modelValue, syncTemp, { immediate: true });

            onMounted(() => {
                document.addEventListener('click', handleClickOutside);
                document.addEventListener('keydown', handleKeydown);
                window.addEventListener('app-select-opened', handleSelectOpened);
            });

            onUnmounted(() => {
                document.removeEventListener('click', handleClickOutside);
                document.removeEventListener('keydown', handleKeydown);
                window.removeEventListener('app-select-opened', handleSelectOpened);
            });

            return {
                open,
                dropUp,
                root,
                hours,
                minutes,
                tempHour,
                tempMinute,
                toggle,
                selectHour,
                selectMinute,
                confirm,
                setNow
            };
        },
        template: `
            <div ref="root" class="time-picker" :class="{ open, 'drop-up': dropUp }">
                <button type="button" class="time-picker__trigger" :disabled="disabled" @click.stop="toggle">
                    <span class="time-picker__value" :class="{ 'time-picker__placeholder': !modelValue }">
                        {{ modelValue || placeholder }}
                    </span>
                    <span class="time-picker__icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3 2"/>
                        </svg>
                    </span>
                </button>

                <transition name="select-pop">
                    <div v-if="open" class="time-picker__panel" @click.stop>
                        <div class="time-picker__columns">
                            <div class="time-picker__column">
                                <button
                                    v-for="hour in hours"
                                    :key="'h-' + hour"
                                    type="button"
                                    class="time-picker__item"
                                    :class="{ active: tempHour === hour }"
                                    @click="selectHour(hour)"
                                >{{ hour }}</button>
                            </div>
                            <div class="time-picker__column">
                                <button
                                    v-for="minute in minutes"
                                    :key="'m-' + minute"
                                    type="button"
                                    class="time-picker__item"
                                    :class="{ active: tempMinute === minute }"
                                    @click="selectMinute(minute)"
                                >{{ minute }}</button>
                            </div>
                        </div>
                        <div class="time-picker__actions">
                            <button type="button" class="time-picker__action" @click="setNow">此刻</button>
                            <button type="button" class="time-picker__action time-picker__confirm" @click="confirm">确定</button>
                        </div>
                    </div>
                </transition>
            </div>
        `
    };
})();
