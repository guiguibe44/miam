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
    const relatedUrl = pageRoot.dataset.planningRelatedUrl;
    const relatedWidget = pageRoot.querySelector('[data-related-widget]');
    const relatedListSimilar = pageRoot.querySelector('[data-related-list-similar]');
    const relatedListDifferent = pageRoot.querySelector('[data-related-list-different]');
    const relatedTitlePrimary = pageRoot.querySelector('[data-related-title-primary]');
    const relatedTitleSecondary = pageRoot.querySelector('[data-related-title-secondary]');
    if (!suggestUrl) {
        return;
    }

    let activeBlock = null;

    function getExcludeIds(currentRecipeId = null) {
        const ids = [];
        pageRoot.querySelectorAll('[data-ac-value]').forEach((input) => {
            const id = Number.parseInt(input.value || '', 10);
            if (Number.isInteger(id) && id > 0 && id !== currentRecipeId) {
                ids.push(id);
            }
        });
        return [...new Set(ids)];
    }

    function buildRelatedItem(item) {
        const li = document.createElement('li');
        li.className = 'planning-related-item';
        const thumb = item.imageUrl
            ? `<img src="${escapeHtml(item.imageUrl)}" alt="" loading="lazy" width="44" height="44">`
            : '';
        li.innerHTML = `${thumb}<button type="button" class="planning-related-pick" data-related-name="${escapeHtml(item.name)}">${escapeHtml(item.name)}</button>`;

        return li;
    }

    function renderRelated(groups) {
        if (
            !(relatedWidget instanceof HTMLElement)
            || !(relatedListSimilar instanceof HTMLElement)
            || !(relatedListDifferent instanceof HTMLElement)
        ) {
            return;
        }
        relatedListSimilar.innerHTML = '';
        relatedListDifferent.innerHTML = '';

        const hasContextual = Array.isArray(groups?.similar) || Array.isArray(groups?.different);
        const primary = hasContextual
            ? (Array.isArray(groups?.similar) ? groups.similar : [])
            : (Array.isArray(groups?.neverSelected) ? groups.neverSelected : []);
        const secondary = hasContextual
            ? (Array.isArray(groups?.different) ? groups.different : [])
            : (Array.isArray(groups?.recentlySelected) ? groups.recentlySelected : []);

        if (relatedTitlePrimary instanceof HTMLElement) {
            relatedTitlePrimary.textContent = hasContextual ? 'Qui ressemblent' : 'Jamais sélectionnées';
        }
        if (relatedTitleSecondary instanceof HTMLElement) {
            relatedTitleSecondary.textContent = hasContextual ? 'Idées différentes' : 'Récemment sélectionnées';
        }

        if (primary.length === 0 && secondary.length === 0) {
            relatedWidget.hidden = true;
            return;
        }

        primary.forEach((item) => relatedListSimilar.appendChild(buildRelatedItem(item)));
        secondary.forEach((item) => relatedListDifferent.appendChild(buildRelatedItem(item)));
        relatedWidget.hidden = false;
    }

    async function loadRelated(recipeId) {
        if (!relatedUrl) {
            return;
        }
        const params = new URLSearchParams({ recipeId: String(recipeId), similarLimit: '3', differentLimit: '3' });
        getExcludeIds(recipeId).forEach((id) => params.append('excludeIds[]', String(id)));
        const r = await fetch(`${relatedUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!r.ok) {
            return;
        }
        const data = await r.json();
        renderRelated(data);
    }

    function loadInitialSuggestions() {
        if (!relatedUrl) {
            return;
        }
        const params = new URLSearchParams({ initialLimit: '3' });
        fetch(`${relatedUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                if (data) {
                    renderRelated(data);
                }
            })
            .catch(() => {});
    }

    if (relatedWidget instanceof HTMLElement) {
        relatedWidget.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement) || !target.classList.contains('planning-related-pick')) {
                return;
            }
            const name = target.getAttribute('data-related-name') || '';
            if (!activeBlock || !name) {
                return;
            }
            const input = activeBlock.querySelector('[data-ac-input]');
            const hidden = activeBlock.querySelector('[data-ac-value]');
            if (input instanceof HTMLInputElement) {
                input.value = name;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
            }
            if (hidden instanceof HTMLInputElement) {
                hidden.value = '';
            }
        });
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
            loadRelated(item.id).catch(() => {});
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
            activeBlock = block;
            if (lastItems.length > 0) {
                openList();
            }
        });
        textInput.addEventListener('click', () => {
            activeBlock = block;
        });
    });

    loadInitialSuggestions();
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
