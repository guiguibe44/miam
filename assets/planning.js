/**
 * Planification : autocomplétion riche + préremplissage du query string au submit global.
 */
function escapeHtml(s) {
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildMetaLine(item) {
    const parts = [];
    if (item.totalTimeMinutes != null) {
        parts.push(`${item.totalTimeMinutes} min`);
    }
    if (item.estimatedCost != null) {
        parts.push(`${item.estimatedCost} €`);
    }
    if (item.caloriesPerPortion) {
        parts.push(`${item.caloriesPerPortion} kcal`);
    }
    if (item.mainIngredient) {
        parts.push(item.mainIngredient);
    }
    if (item.difficulty) {
        parts.push(item.difficulty);
    }
    return parts.map(escapeHtml).join(' · ');
}

function initPlanningAutocomplete(pageRoot) {
    const suggestUrl = pageRoot.dataset.planningSuggestUrl;
    if (!suggestUrl) {
        return;
    }

    pageRoot.querySelectorAll('[data-planning-autocomplete]').forEach((block) => {
        const valueInput = block.querySelector('[data-ac-value]');
        const textInput = block.querySelector('[data-ac-input]');
        const list = block.querySelector('[data-ac-list]');
        if (!valueInput || !textInput || !list) {
            return;
        }

        let timer = null;
        let activeIdx = -1;
        let lastItems = [];

        function closeList() {
            list.hidden = true;
            activeIdx = -1;
            textInput.setAttribute('aria-expanded', 'false');
            textInput.removeAttribute('aria-activedescendant');
        }

        function openList() {
            if (lastItems.length === 0) {
                return;
            }
            list.hidden = false;
            textInput.setAttribute('aria-expanded', 'true');
        }

        function renderItems(items) {
            lastItems = items;
            list.innerHTML = '';
            items.forEach((item, i) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.id = `plan-suggest-${block.id || 'x'}-${i}`;
                li.className = 'plan-suggest__item plan-suggest__item--rich';
                li.dataset.recipeId = String(item.id);
                li.dataset.recipeName = item.name;

                const thumb = item.imageUrl
                    ? `<span class="plan-suggest__thumb plan-suggest__thumb--lg"><img src="${escapeHtml(item.imageUrl)}" alt="" loading="lazy" width="56" height="56"></span>`
                    : '<span class="plan-suggest__thumb plan-suggest__thumb--lg plan-suggest__thumb--empty" aria-hidden="true"></span>';

                const meta = buildMetaLine(item);
                li.innerHTML = `${thumb}<span class="plan-suggest__body"><span class="plan-suggest__name">${escapeHtml(item.name)}</span><span class="plan-suggest__meta">${meta}</span></span>`;
                li.addEventListener('mousedown', (e) => e.preventDefault());
                li.addEventListener('click', () => selectItem(item));
                list.appendChild(li);
            });
            if (items.length > 0) {
                openList();
            } else {
                closeList();
            }
        }

        function selectItem(item) {
            valueInput.value = String(item.id);
            textInput.value = item.name;
            closeList();
            textInput.focus();
        }

        function highlightActive() {
            const opts = list.querySelectorAll('.plan-suggest__item');
            opts.forEach((el, i) => {
                el.classList.toggle('is-active', i === activeIdx);
            });
            if (activeIdx >= 0 && opts[activeIdx]) {
                textInput.setAttribute('aria-activedescendant', opts[activeIdx].id);
            } else {
                textInput.removeAttribute('aria-activedescendant');
            }
        }

        async function fetchSuggestions(q) {
            const url = `${suggestUrl}?${new URLSearchParams({ q, limit: '8', page: '1' })}`;
            const r = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!r.ok) {
                return;
            }
            const data = await r.json();
            if (Array.isArray(data.items)) {
                renderItems(data.items);
            }
        }

        textInput.addEventListener('input', () => {
            valueInput.value = '';
            clearTimeout(timer);
            const q = textInput.value.trim();
            if (q.length < 2) {
                lastItems = [];
                list.innerHTML = '';
                closeList();

                return;
            }
            timer = setTimeout(() => {
                fetchSuggestions(q).catch(() => {});
            }, 280);
        });

        textInput.addEventListener('keydown', (e) => {
            if (list.hidden || lastItems.length === 0) {
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = Math.min(activeIdx + 1, lastItems.length - 1);
                highlightActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = Math.max(activeIdx - 1, 0);
                highlightActive();
            } else if (e.key === 'Enter' && activeIdx >= 0) {
                e.preventDefault();
                selectItem(lastItems[activeIdx]);
            } else if (e.key === 'Escape') {
                closeList();
            }
        });

        textInput.addEventListener('blur', () => {
            setTimeout(closeList, 160);
        });

        textInput.addEventListener('focus', () => {
            if (lastItems.length > 0) {
                openList();
            }
        });
    });
}

function initPlanningSaveForm() {
    document.querySelectorAll('[data-planning-save-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('.plan-day-card').forEach((dayCard) => {
                const dayToggle = dayCard.querySelector('[data-day-toggle]');
                const slotToggles = Array.from(dayCard.querySelectorAll('[data-slot-toggle]'));
                if (dayToggle instanceof HTMLInputElement) {
                    dayToggle.checked = slotToggles.some((el) => el.checked);
                }
            });
            const rq = form.querySelector('[data-planning-return-query]');
            if (rq) {
                rq.value = window.location.search.startsWith('?') ? window.location.search.slice(1) : '';
            }
        });
    });
}

function initPlanningSlotToggles() {
    document.querySelectorAll('.plan-day-card').forEach((dayCard) => {
        const dayToggle = dayCard.querySelector('[data-day-toggle]');
        const slotToggles = Array.from(dayCard.querySelectorAll('[data-slot-toggle]'));

        function syncBlockState(slotToggle) {
            const block = slotToggle.closest('[data-meal-block]');
            if (!block) {
                return;
            }
            const enabled = slotToggle.checked;
            block.classList.toggle('is-disabled', !enabled);
            block.querySelectorAll('[data-planning-autocomplete]').forEach((picker) => {
                picker.classList.toggle('is-disabled', !enabled);
            });
            block.querySelectorAll('[data-ac-input]').forEach((input) => {
                if (enabled) {
                    input.disabled = false;
                    input.removeAttribute('disabled');
                } else {
                    input.disabled = true;
                    input.setAttribute('disabled', 'disabled');
                }
            });
        }

        slotToggles.forEach((slotToggle) => {
            syncBlockState(slotToggle);
            slotToggle.addEventListener('change', () => {
                syncBlockState(slotToggle);
                if (dayToggle) {
                    dayToggle.checked = slotToggles.some((el) => el.checked);
                }
                if (slotToggle.checked) {
                    const block = slotToggle.closest('[data-meal-block]');
                    const input = block?.querySelector('[data-ac-input]');
                    if (input instanceof HTMLInputElement) {
                        input.focus();
                    }
                }
            });

            const block = slotToggle.closest('[data-meal-block]');
            const input = block?.querySelector('[data-ac-input]');
            if (block instanceof HTMLElement) {
                block.addEventListener('click', (event) => {
                    if (!block.classList.contains('is-disabled')) {
                        return;
                    }
                    const target = event.target;
                    if (target instanceof HTMLElement && target.closest('[data-slot-toggle]')) {
                        return;
                    }
                    slotToggle.checked = true;
                    syncBlockState(slotToggle);
                    if (dayToggle) {
                        dayToggle.checked = true;
                    }
                    if (input instanceof HTMLInputElement) {
                        input.focus();
                    }
                });
            }
            if (input instanceof HTMLInputElement) {
                input.addEventListener('focus', () => {
                    if (input.disabled) {
                        slotToggle.checked = true;
                        syncBlockState(slotToggle);
                        if (dayToggle) {
                            dayToggle.checked = true;
                        }
                        // Re-focus once enabled so user can type immediately.
                        setTimeout(() => input.focus(), 0);
                    }
                });
            }
        });

        if (dayToggle) {
            dayToggle.addEventListener('change', () => {
                const target = dayToggle.checked;
                slotToggles.forEach((slotToggle) => {
                    slotToggle.checked = target;
                    syncBlockState(slotToggle);
                });
            });
        }
    });
}

document.querySelectorAll('.planning-page').forEach(initPlanningAutocomplete);
initPlanningSaveForm();
initPlanningSlotToggles();
