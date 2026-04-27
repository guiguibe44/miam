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
    if (pageRoot.dataset.miamPlanningAcInit === '1') {
        return;
    }
    const suggestUrl = pageRoot.dataset.planningSuggestUrl;
    const relatedUrl = pageRoot.dataset.planningRelatedUrl;
    const relatedWidget = pageRoot.querySelector('[data-related-widget]');
    const relatedListSimilar = pageRoot.querySelector('[data-related-list-similar]');
    const relatedListDifferent = pageRoot.querySelector('[data-related-list-different]');
    const relatedTitlePrimary = pageRoot.querySelector('[data-related-title-primary]');
    const relatedTitleSecondary = pageRoot.querySelector('[data-related-title-secondary]');
    const relatedBody = pageRoot.querySelector('[data-related-body]');
    const relatedToggle = pageRoot.querySelector('[data-related-toggle]');
    if (!suggestUrl) {
        return;
    }

    pageRoot.dataset.miamPlanningAcInit = '1';

    let activeBlock = null;

    function setRelatedCollapsed(collapsed) {
        if (!(relatedWidget instanceof HTMLElement)) {
            return;
        }
        relatedWidget.classList.toggle('is-collapsed', collapsed);
        if (relatedToggle instanceof HTMLButtonElement) {
            relatedToggle.textContent = collapsed ? 'Afficher' : 'Masquer';
            relatedToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

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

    if (relatedToggle instanceof HTMLButtonElement && relatedBody instanceof HTMLElement) {
        relatedToggle.addEventListener('click', () => {
            const collapsed = !relatedWidget.classList.contains('is-collapsed');
            setRelatedCollapsed(collapsed);
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
    setRelatedCollapsed(true);
}

function initPlanningRecipeLibrary(pageRoot) {
    if (pageRoot.dataset.miamPlanningLibraryInit === '1') {
        return;
    }

    const libraryUrl = pageRoot.dataset.planningLibraryUrl;
    const searchInput = pageRoot.querySelector('[data-planning-library-search]');
    const listRoot = pageRoot.querySelector('[data-planning-library-list]');
    if (!libraryUrl || !(searchInput instanceof HTMLInputElement) || !(listRoot instanceof HTMLElement)) {
        return;
    }

    pageRoot.dataset.miamPlanningLibraryInit = '1';
    let timer = null;
    let activeRecipe = null;
    const pickModal = pageRoot.querySelector('[data-planning-pick-modal]');
    const pickForm = pageRoot.querySelector('[data-planning-pick-form]');
    const pickSlot = pageRoot.querySelector('[data-planning-pick-slot]');
    const pickRecipe = pageRoot.querySelector('[data-planning-pick-recipe]');
    const pickSubmit = pageRoot.querySelector('[data-planning-pick-submit]');
    const pickCloseButtons = pageRoot.querySelectorAll('[data-planning-pick-close]');
    const detailModal = pageRoot.querySelector('[data-planning-recipe-modal]');
    const detailFrame = pageRoot.querySelector('[data-planning-recipe-frame]');
    const detailTitle = pageRoot.querySelector('[data-planning-recipe-title]');
    const detailCloseButtons = pageRoot.querySelectorAll('[data-planning-recipe-close]');

    function assignRecipeToTarget(target, payload) {
        if (!payload || !payload.id || !payload.name) {
            return;
        }
        const mealBlock = target.closest('[data-meal-block]');
        if (mealBlock?.classList.contains('is-disabled')) {
            return;
        }
        const hidden = target.querySelector('[data-ac-value]');
        const input = target.querySelector('[data-ac-input]');
        const dropzone = target.querySelector('[data-slot-dropzone]');
        const emptyHint = target.querySelector('[data-slot-dropzone-empty]');
        const preview = target.querySelector('[data-slot-preview]');
        const previewMedia = target.querySelector('[data-slot-preview-media]');
        const previewImage = target.querySelector('[data-slot-preview-image]');
        const previewLink = target.querySelector('[data-slot-preview-link]');
        const clearBtn = target.querySelector('[data-slot-clear]');
        if (hidden instanceof HTMLInputElement) {
            hidden.value = String(payload.id);
        }
        if (input instanceof HTMLInputElement) {
            input.value = String(payload.name);
        }
        if (dropzone instanceof HTMLElement) {
            dropzone.classList.add('has-recipe');
        }
        if (emptyHint instanceof HTMLElement) {
            emptyHint.hidden = true;
        }
        if (clearBtn instanceof HTMLElement) {
            clearBtn.hidden = false;
        }
        if (previewLink instanceof HTMLAnchorElement) {
            previewLink.href = `/recettes/${payload.id}`;
            previewLink.textContent = String(payload.name);
            previewLink.hidden = false;
        }
        if (preview instanceof HTMLElement) {
            preview.hidden = false;
        }
        const imageUrl = typeof payload.imageUrl === 'string' ? payload.imageUrl.trim() : '';
        if (previewMedia instanceof HTMLElement) {
            previewMedia.hidden = imageUrl === '';
        }
        if (previewImage instanceof HTMLImageElement) {
            previewImage.src = imageUrl;
        }
    }

    function selectedRecipeIds() {
        const ids = new Set();
        pageRoot.querySelectorAll('[data-ac-value]').forEach((input) => {
            const id = Number.parseInt(input.value || '', 10);
            if (Number.isInteger(id) && id > 0) {
                ids.add(id);
            }
        });

        return ids;
    }

    function updateLibraryHighlights() {
        const ids = selectedRecipeIds();
        listRoot.querySelectorAll('.planning-library-item').forEach((card) => {
            const id = Number.parseInt(card.getAttribute('data-recipe-id') || '', 10);
            card.classList.toggle('is-linked', Number.isInteger(id) && ids.has(id));
        });
    }

    function freeSlotTargets() {
        const targets = [];
        pageRoot.querySelectorAll('[data-slot-drop-target]').forEach((target) => {
            const block = target.closest('[data-meal-block]');
            if (block?.classList.contains('is-disabled')) {
                return;
            }
            const hidden = target.querySelector('[data-ac-value]');
            if (!(hidden instanceof HTMLInputElement) || hidden.value.trim() !== '') {
                return;
            }
            const dayCard = target.closest('.plan-day-card');
            const dayTitle = dayCard?.querySelector('.plan-day-card__title')?.textContent?.trim() || 'Jour';
            const mealTitle = block?.querySelector('.plan-meal-block__title')?.textContent?.trim() || 'Repas';
            targets.push({
                label: `${dayTitle} — ${mealTitle}`,
                target,
            });
        });

        return targets;
    }

    function closePickModal() {
        if (pickModal instanceof HTMLDialogElement && pickModal.open) {
            pickModal.close();
        }
    }

    function closeDetailModal() {
        if (detailModal instanceof HTMLDialogElement && detailModal.open) {
            detailModal.close();
        }
        if (detailFrame instanceof HTMLIFrameElement) {
            detailFrame.src = 'about:blank';
        }
    }

    function openDetailModal(item) {
        if (
            !(detailModal instanceof HTMLDialogElement)
            || !(detailFrame instanceof HTMLIFrameElement)
            || !(detailTitle instanceof HTMLElement)
        ) {
            return;
        }
        detailTitle.textContent = item.name || 'Détail recette';
        detailFrame.src = `/recettes/${item.id}`;
        detailModal.showModal();
    }

    function openPickModal(payload) {
        if (
            !(pickModal instanceof HTMLDialogElement)
            || !(pickSlot instanceof HTMLSelectElement)
            || !(pickRecipe instanceof HTMLElement)
            || !(pickSubmit instanceof HTMLButtonElement)
        ) {
            return false;
        }

        const slots = freeSlotTargets();
        pickRecipe.textContent = `Recette : ${payload.name || 'Recette'}`;
        pickSlot.innerHTML = '';

        if (slots.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Aucun créneau libre disponible';
            pickSlot.appendChild(option);
            pickSlot.disabled = true;
            pickSubmit.disabled = true;
        } else {
            slots.forEach((slot, index) => {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = slot.label;
                pickSlot.appendChild(option);
            });
            pickSlot.disabled = false;
            pickSubmit.disabled = false;
        }

        pickSubmit.onclick = () => {
            if (pickSubmit.disabled) {
                return;
            }
            const idx = Number.parseInt(pickSlot.value, 10);
            if (!Number.isInteger(idx) || !slots[idx]) {
                return;
            }
            assignRecipeToTarget(slots[idx].target, payload);
            closePickModal();
            updateLibraryHighlights();
        };

        pickModal.showModal();

        return true;
    }

    function renderItems(items) {
        listRoot.innerHTML = '';
        if (!Array.isArray(items) || items.length === 0) {
            listRoot.innerHTML = '<p class="muted">Aucune recette.</p>';

            return;
        }

        items.forEach((item) => {
            const card = document.createElement('article');
            card.className = 'planning-library-item';
            card.dataset.recipeId = String(item.id);
            card.dataset.recipeName = item.name || '';

            const thumb = item.imageUrl
                ? `<img src="${escapeHtml(item.imageUrl)}" alt="" loading="lazy" width="44" height="44">`
                : '<div class="planning-library-item__thumb-empty" aria-hidden="true">🍽</div>';
            card.innerHTML = `
                <div class="planning-library-item__thumb">${thumb}</div>
                <div class="planning-library-item__body">
                    <strong>${escapeHtml(item.name || 'Recette')}</strong>
                    <div class="planning-library-item__actions">
                        <a href="/recettes/${item.id}" target="_blank" rel="noopener noreferrer" class="btn btn--secondary">Ouvrir</a>
                        <button
                            type="button"
                            class="btn btn--primary btn--icon-only"
                            data-library-drag
                            draggable="true"
                            aria-label="Glisser la recette"
                            title="Glisser la recette"
                        >⤢</button>
                    </div>
                </div>
            `;
            const payload = { id: item.id, name: item.name || '', imageUrl: item.imageUrl || '' };
            const dragButton = card.querySelector('[data-library-drag]');

            if (dragButton instanceof HTMLButtonElement) {
                dragButton.addEventListener('dragstart', (event) => {
                    if (!event.dataTransfer) {
                        return;
                    }
                    event.dataTransfer.setData('text/plain', JSON.stringify(payload));
                    event.dataTransfer.effectAllowed = 'copy';
                    card.classList.add('is-dragging');
                });
                dragButton.addEventListener('dragend', () => {
                    card.classList.remove('is-dragging');
                });
                dragButton.addEventListener('click', () => {
                    activeRecipe = payload;
                    listRoot.querySelectorAll('.planning-library-item').forEach((el) => el.classList.remove('is-active'));
                    card.classList.add('is-active');
                    openPickModal(payload);
                });
            }

            listRoot.appendChild(card);
        });
        updateLibraryHighlights();
    }

    async function loadLibrary(query) {
        const params = new URLSearchParams();
        params.set('limit', '60');
        if (query.trim() !== '') {
            params.set('q', query.trim());
        }
        const response = await fetch(`${libraryUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const data = await response.json();
        renderItems(Array.isArray(data.items) ? data.items : []);
    }

    function bindDropTargets() {
        pageRoot.querySelectorAll('[data-slot-drop-target]').forEach((target) => {
            if (target.dataset.dropBound === '1') {
                return;
            }
            target.dataset.dropBound = '1';

            target.addEventListener('dragover', (event) => {
                const mealBlock = target.closest('[data-meal-block]');
                if (mealBlock?.classList.contains('is-disabled')) {
                    return;
                }
                event.preventDefault();
                target.classList.add('is-drop-over');
            });

            target.addEventListener('dragleave', () => {
                target.classList.remove('is-drop-over');
            });

            target.addEventListener('drop', (event) => {
                target.classList.remove('is-drop-over');
                const mealBlock = target.closest('[data-meal-block]');
                if (mealBlock?.classList.contains('is-disabled')) {
                    return;
                }

                event.preventDefault();
                const raw = event.dataTransfer?.getData('text/plain') || '';
                if (!raw) {
                    return;
                }
                let payload = null;
                try {
                    payload = JSON.parse(raw);
                } catch {
                    payload = null;
                }
                if (!payload || !payload.id || !payload.name) {
                    return;
                }
                assignRecipeToTarget(target, payload);
                updateLibraryHighlights();
            });

            target.addEventListener('click', (event) => {
                const t = event.target;
                if (t instanceof HTMLElement) {
                    if (t.closest('[data-slot-clear]')) {
                        return;
                    }
                    if (t.closest('a.plan-dropzone__title')) {
                        return;
                    }
                }
                if (!activeRecipe) {
                    return;
                }
                assignRecipeToTarget(target, activeRecipe);
                updateLibraryHighlights();
            });
        });
    }

    function bindClearButtons() {
        pageRoot.querySelectorAll('[data-slot-clear]').forEach((button) => {
            if (button.dataset.clearBound === '1') {
                return;
            }
            button.dataset.clearBound = '1';
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                const block = button.closest('[data-meal-block]');
                const picker = block?.querySelector('[data-slot-drop-target]');
                const hidden = block?.querySelector('[data-ac-value]');
                const input = block?.querySelector('[data-ac-input]');
                const preview = picker?.querySelector('[data-slot-preview]');
                const previewMedia = picker?.querySelector('[data-slot-preview-media]');
                const previewImage = picker?.querySelector('[data-slot-preview-image]');
                const previewLink = picker?.querySelector('[data-slot-preview-link]');
                const dropzone = picker?.querySelector('[data-slot-dropzone]');
                const emptyHint = picker?.querySelector('[data-slot-dropzone-empty]');
                const clearBtn = picker?.querySelector('[data-slot-clear]');

                if (hidden instanceof HTMLInputElement) {
                    hidden.value = '';
                }
                if (input instanceof HTMLInputElement) {
                    input.value = '';
                }
                if (previewLink instanceof HTMLAnchorElement) {
                    previewLink.textContent = '';
                    previewLink.href = '#';
                    previewLink.hidden = true;
                }
                if (previewImage instanceof HTMLImageElement) {
                    previewImage.src = '';
                }
                if (previewMedia instanceof HTMLElement) {
                    previewMedia.hidden = true;
                }
                if (preview instanceof HTMLElement) {
                    preview.hidden = true;
                }
                if (dropzone instanceof HTMLElement) {
                    dropzone.classList.remove('has-recipe');
                }
                if (emptyHint instanceof HTMLElement) {
                    emptyHint.hidden = false;
                }
                if (clearBtn instanceof HTMLElement) {
                    clearBtn.hidden = true;
                }
                updateLibraryHighlights();
            });
        });
    }

    if (pickModal instanceof HTMLDialogElement) {
        pickCloseButtons.forEach((button) => {
            button.addEventListener('click', closePickModal);
        });
        if (pickForm instanceof HTMLElement) {
            pickForm.addEventListener('click', (event) => {
                event.stopPropagation();
            });
        }
    }

    if (detailModal instanceof HTMLDialogElement) {
        detailCloseButtons.forEach((button) => {
            button.addEventListener('click', closeDetailModal);
        });
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            loadLibrary(searchInput.value).catch(() => {
                listRoot.innerHTML = '<p class="muted">Impossible de charger les recettes.</p>';
            });
        }, 250);
    });

    bindDropTargets();
    bindClearButtons();
    loadLibrary('').catch(() => {
        listRoot.innerHTML = '<p class="muted">Impossible de charger les recettes.</p>';
    });
    updateLibraryHighlights();
}

function initPlanningSaveForm() {
    document.querySelectorAll('[data-planning-save-form]').forEach((form) => {
        if (form.dataset.miamPlanningSaveBound === '1') {
            return;
        }
        form.dataset.miamPlanningSaveBound = '1';
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
        if (dayCard.dataset.miamPlanningSlotsInit === '1') {
            return;
        }
        dayCard.dataset.miamPlanningSlotsInit = '1';
        const dayToggle = dayCard.querySelector('[data-day-toggle]');
        const slotToggles = Array.from(dayCard.querySelectorAll('[data-slot-toggle]'));

        function syncBlockState(slotToggle) {
            const block = slotToggle.closest('[data-meal-block]');
            if (!block) {
                return;
            }
            const enabled = slotToggle.checked;
            block.classList.toggle('is-disabled', !enabled);
            const body = block.querySelector('.plan-meal-block__body');
            const picker = block.querySelector('[data-slot-drop-target]');
            const assignedInput = picker?.querySelector('[data-ac-value]');
            const dropzone = picker?.querySelector('[data-slot-dropzone]');
            const emptyHint = picker?.querySelector('[data-slot-dropzone-empty]');
            const preview = picker?.querySelector('[data-slot-preview]');
            const clearBtn = picker?.querySelector('[data-slot-clear]');
            const previewLink = picker?.querySelector('[data-slot-preview-link]');
            const hasRecipe = assignedInput instanceof HTMLInputElement && assignedInput.value.trim() !== '';

            if (body instanceof HTMLElement) {
                if (enabled) {
                    body.hidden = false;
                    body.removeAttribute('hidden');
                    body.style.setProperty('display', 'block', 'important');
                    // Force un recalcul immédiat dans Firefox après réactivation.
                    void body.offsetHeight;
                } else {
                    body.hidden = true;
                    body.setAttribute('hidden', '');
                    body.style.setProperty('display', 'none', 'important');
                }
            }

            if (enabled && !hasRecipe) {
                if (dropzone instanceof HTMLElement) {
                    dropzone.classList.remove('has-recipe');
                }
                if (emptyHint instanceof HTMLElement) {
                    emptyHint.hidden = false;
                }
                if (preview instanceof HTMLElement) {
                    preview.hidden = true;
                }
                if (clearBtn instanceof HTMLElement) {
                    clearBtn.hidden = true;
                }
                if (previewLink instanceof HTMLAnchorElement) {
                    previewLink.hidden = true;
                    previewLink.textContent = '';
                    previewLink.href = '#';
                }
            }

            if (!enabled) {
                const clearBtn = block.querySelector('[data-slot-clear]');
                if (clearBtn instanceof HTMLButtonElement) {
                    clearBtn.click();
                }
            }

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

function bootPlanningPage() {
    document.querySelectorAll('.planning-page').forEach((pageRoot) => {
        try {
            initPlanningAutocomplete(pageRoot);
        } catch {
            // L'autocomplete des créneaux est optionnel : ne pas bloquer la bibliothèque.
        }
        initPlanningRecipeLibrary(pageRoot);
    });
    initPlanningSaveForm();
    initPlanningSlotToggles();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootPlanningPage);
} else {
    bootPlanningPage();
}
window.addEventListener('load', bootPlanningPage);
document.addEventListener('turbo:load', bootPlanningPage);
