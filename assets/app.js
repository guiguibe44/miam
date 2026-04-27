import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

window.__miamAppLoaded = true;
document.documentElement.setAttribute('data-miam-app-loaded', '1');
console.log('[app] app.js charge');

/**
 * Avec Turbo Drive, la première import map de la visite reste celle du navigateur :
 * les pages qui ajoutent un second entrypoint (planning, recipe_index) ne chargent pas leur JS
 * si on y accède par navigation interne. On importe donc ces modules depuis app (toujours chargé).
 */
function loadPageEntrypoints() {
    if (document.querySelector('.planning-page')) {
        import('planning')
            .then(() => {
                console.log('[app] entrypoint planning charge');
                document.documentElement.setAttribute('data-miam-planning-imported', '1');
            })
            .catch((error) => {
                console.error('[app] Impossible de charger entrypoint "planning"', error);
                document.documentElement.setAttribute('data-miam-planning-imported', 'error');
            });
    }
    if (document.querySelector('[data-recipe-list-app]')) {
        import('recipe_index').catch((error) => {
            console.error('[app] Impossible de charger entrypoint "recipe_index"', error);
        });
    }
    if (document.querySelector('form.stack-form[data-recipe-form-auto-save]')) {
        import('recipe_form').catch((error) => {
            console.error('[app] Impossible de charger entrypoint "recipe_form"', error);
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadPageEntrypoints);
} else {
    loadPageEntrypoints();
}
window.addEventListener('load', loadPageEntrypoints);
document.addEventListener('turbo:load', loadPageEntrypoints);
document.addEventListener('turbo:render', loadPageEntrypoints);
