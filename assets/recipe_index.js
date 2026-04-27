/**
 * Liste recettes : mise à jour live de la grille selon le champ « nom » (debounce),
 * filtres via « Appliquer », pagination AJAX, historique (replaceState pour la saisie).
 */
let recipeListPopstateCallback = null;
let recipeListPopstateAttached = false;

function attachRecipeListPopstateOnce() {
    if (recipeListPopstateAttached) {
        return;
    }
    recipeListPopstateAttached = true;
    window.addEventListener('popstate', () => {
        recipeListPopstateCallback?.();
    });
}

function initRecipeListApp(root) {
    if (root.dataset.miamRecipeListInit === '1') {
        return;
    }
    const fragmentUrl = root.dataset.fragmentUrl;
    const indexPath = root.dataset.indexPath || '/recettes';
    const form = root.querySelector('#recipe-filters-form');
    const results = root.querySelector('[data-recipe-results]');
    const searchInput = form?.querySelector('input[name="q"]');

    if (!form || !results || !fragmentUrl || !searchInput) {
        return;
    }

    root.dataset.miamRecipeListInit = '1';

    let searchTimer = null;
    const planModal = document.querySelector('[data-recipe-plan-modal]');
    const planForm = planModal?.querySelector('[data-recipe-plan-form]');
    const planTitle = planModal?.querySelector('[data-recipe-plan-title]');
    const planToken = planModal?.querySelector('[data-recipe-plan-token]');
    const planWeek = planModal?.querySelector('[data-recipe-plan-week]');
    const planSlot = planModal?.querySelector('[data-recipe-plan-slot]');
    const closeButtons = planModal ? planModal.querySelectorAll('[data-recipe-plan-close]') : [];
    const planRouteTemplate = root.dataset.planRouteTemplate || '';
    let availableSlotsByWeek = {};
    try {
        availableSlotsByWeek = JSON.parse(root.dataset.planAvailableSlots || '{}');
    } catch (e) {
        availableSlotsByWeek = {};
    }

    function syncSlotOptionsForWeek() {
        if (!(planWeek instanceof HTMLSelectElement) || !(planSlot instanceof HTMLSelectElement)) {
            return;
        }
        const slots = availableSlotsByWeek[planWeek.value] || [];
        const previousValue = planSlot.value;
        planSlot.innerHTML = '';
        if (!Array.isArray(slots) || slots.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Aucun créneau libre pour cette planification';
            planSlot.appendChild(option);
            planSlot.value = '';
            planSlot.disabled = true;

            return;
        }
        const selectableValues = [];
        slots.forEach((slot) => {
            const option = document.createElement('option');
            option.value = slot.value;
            option.textContent = slot.label;
            option.disabled = !!slot.readonly;
            planSlot.appendChild(option);
            if (!slot.readonly) {
                selectableValues.push(slot.value);
            }
        });
        if (selectableValues.length === 0) {
            planSlot.value = '';
            planSlot.disabled = true;

            return;
        }
        planSlot.disabled = false;
        const stillExists = selectableValues.includes(previousValue);
        planSlot.value = stillExists ? previousValue : selectableValues[0];
    }

    function closePlanModal() {
        if (planModal && typeof planModal.close === 'function' && planModal.open) {
            planModal.close();
        }
    }

    if (planModal) {
        closeButtons.forEach((button) => {
            button.addEventListener('click', closePlanModal);
        });
        planModal.addEventListener('click', (e) => {
            if (e.target === planModal) {
                closePlanModal();
            }
        });
        if (planWeek instanceof HTMLSelectElement) {
            planWeek.addEventListener('change', syncSlotOptionsForWeek);
        }
    }

    async function fetchFragment(queryString) {
        const url = queryString ? `${fragmentUrl}?${queryString}` : fragmentUrl;
        results.classList.add('is-loading');
        try {
            const r = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!r.ok) {
                throw new Error(`HTTP ${r.status}`);
            }
            const data = await r.json();
            if (typeof data.html === 'string') {
                results.innerHTML = data.html;
            }
        } catch (err) {
            console.error('[recipe_index]', err);
        } finally {
            results.classList.remove('is-loading');
        }
    }

    function queryStringFromForm() {
        return new URLSearchParams(new FormData(form)).toString();
    }

    /**
     * Applique les paramètres d’URL aux champs du formulaire (sauf page, absent du form).
     */
    function applySearchToForm(search) {
        const raw = search.startsWith('?') ? search.slice(1) : search;
        if (raw === '') {
            return;
        }
        const params = new URLSearchParams(raw);
        for (const [key, value] of params) {
            if (key === 'page') {
                continue;
            }
            const el = form.elements.namedItem(key);
            if (el instanceof RadioNodeList) {
                const r = Array.from(el).find((node) => node.value === value);
                if (r) {
                    r.checked = true;
                }
            } else if (el && 'value' in el) {
                el.value = value;
            }
        }
    }

    function refreshFromForm(historyMode) {
        const qs = queryStringFromForm();
        fetchFragment(qs);
        const href = indexPath + (qs ? `?${qs}` : '');
        if (historyMode === 'replace') {
            history.replaceState({}, '', href);
        } else {
            history.pushState({}, '', href);
        }
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            refreshFromForm('replace');
        }, 320);
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        refreshFromForm('push');
    });

    form.addEventListener('click', (e) => {
        const reset = e.target.closest('[data-recipe-reset]');
        if (!reset) {
            return;
        }
        e.preventDefault();
        form.reset();
        fetchFragment('');
        history.pushState({}, '', indexPath);
    });

    results.addEventListener('click', (e) => {
        const planOpen = e.target.closest('[data-recipe-plan-open]');
        if (planOpen) {
            if (!planModal || !planForm || !planTitle || !planToken || !planRouteTemplate) {
                return;
            }
            const recipeId = planOpen.getAttribute('data-recipe-id');
            const recipeName = planOpen.getAttribute('data-recipe-name') || 'Recette';
            const token = planOpen.getAttribute('data-recipe-plan-token') || '';
            if (!recipeId) {
                return;
            }

            planTitle.textContent = `Recette : ${recipeName}`;
            planToken.value = token;
            planForm.setAttribute('action', planRouteTemplate.replace('__RECIPE_ID__', recipeId));
            syncSlotOptionsForWeek();
            if (typeof planModal.showModal === 'function') {
                planModal.showModal();
            }

            return;
        }

        const a = e.target.closest('nav.pagination a[href]');
        if (!a) {
            return;
        }
        const disabled =
            a.classList.contains('pagination__disabled') || a.getAttribute('aria-disabled') === 'true';
        if (disabled) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        let url;
        try {
            url = new URL(a.href, window.location.origin);
        } catch {
            return;
        }
        const q = url.search.slice(1);
        applySearchToForm(url.search);
        fetchFragment(q);
        history.pushState({}, '', url.pathname + url.search);
    });

    attachRecipeListPopstateOnce();
    recipeListPopstateCallback = () => {
        applySearchToForm(window.location.search);
        fetchFragment(window.location.search.slice(1));
    };
}

function bootRecipeIndexPage() {
    const roots = document.querySelectorAll('[data-recipe-list-app]');
    if (roots.length === 0) {
        recipeListPopstateCallback = null;

        return;
    }
    roots.forEach(initRecipeListApp);
}

bootRecipeIndexPage();
document.addEventListener('turbo:load', bootRecipeIndexPage);
