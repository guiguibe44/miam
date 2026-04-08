/**
 * Liste recettes : mise à jour live de la grille selon le champ « nom » (debounce),
 * filtres via « Appliquer », pagination AJAX, historique (replaceState pour la saisie).
 */
function initRecipeListApp(root) {
    const fragmentUrl = root.dataset.fragmentUrl;
    const indexPath = root.dataset.indexPath || '/recettes';
    const form = root.querySelector('#recipe-filters-form');
    const results = root.querySelector('[data-recipe-results]');
    const searchInput = form?.querySelector('input[name="q"]');

    if (!form || !results || !fragmentUrl || !searchInput) {
        return;
    }

    let searchTimer = null;

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

    window.addEventListener('popstate', () => {
        applySearchToForm(window.location.search);
        fetchFragment(window.location.search.slice(1));
    });
}

document.querySelectorAll('[data-recipe-list-app]').forEach(initRecipeListApp);
