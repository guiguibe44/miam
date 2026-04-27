/**
 * Sauvegarde automatique (édition) : debounce, fetch JSON, message éphémère.
 */
function showRecipeToast(message, variant) {
    let host = document.querySelector('.app-toast-host');
    if (!host) {
        host = document.createElement('div');
        host.className = 'app-toast-host';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);
    }

    const el = document.createElement('p');
    el.className = `app-toast app-toast--${variant === 'error' ? 'error' : 'success'}`;
    el.textContent = message;
    host.appendChild(el);

    const remove = () => {
        el.classList.add('app-toast--leaving');
        setTimeout(() => {
            el.remove();
            if (host && host.childElementCount === 0) {
                host.remove();
            }
        }, 280);
    };

    requestAnimationFrame(() => {
        el.classList.add('app-toast--in');
    });

    setTimeout(remove, variant === 'error' ? 5200 : 2400);
}

function initRecipeFormAutoSave() {
    const form = document.querySelector('form.stack-form[data-recipe-form-auto-save]');
    if (!form || form.dataset.recipeAutosaveBound === '1') {
        return;
    }
    form.dataset.recipeAutosaveBound = '1';

    const action = form.getAttribute('action') || window.location.href;
    let debounceTimer = null;
    let saving = false;
    let resavePending = false;

    function doSave() {
        if (saving) {
            resavePending = true;
            return;
        }

        const data = new FormData(form);
        saving = true;

        fetch(action, {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Recipe-Auto-Save': '1',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })
            .then(async (res) => {
                const text = await res.text();
                let json = null;
                try {
                    json = text ? JSON.parse(text) : null;
                } catch {
                    json = null;
                }

                if (res.status === 422 && json && Array.isArray(json.errors)) {
                    showRecipeToast(
                        json.errors.length ? json.errors[0] : 'Certains champs sont invalides.',
                        'error'
                    );
                    return;
                }
                if (res.status === 409 && json && json.message) {
                    showRecipeToast(json.message, 'error');
                    return;
                }
                if (!res.ok || !json) {
                    showRecipeToast('Enregistrement impossible. Réessayez plus tard.', 'error');
                    return;
                }
                if (json.saved) {
                    showRecipeToast('Enregistré', 'success');
                }
            })
            .catch(() => {
                showRecipeToast('Enregistrement impossible. Réessayez plus tard.', 'error');
            })
            .finally(() => {
                saving = false;
                if (resavePending) {
                    resavePending = false;
                    doSave();
                }
            });
    }

    function schedule() {
        if (saving) {
            resavePending = true;
        }
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            doSave();
        }, 900);
    }

    form.addEventListener('input', schedule, true);
    form.addEventListener('change', schedule, true);
    form.addEventListener('recipe-form-autosave:dirty', schedule);

    form.addEventListener('submit', () => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRecipeFormAutoSave);
} else {
    initRecipeFormAutoSave();
}
document.addEventListener('turbo:load', initRecipeFormAutoSave);
document.addEventListener('turbo:render', initRecipeFormAutoSave);
