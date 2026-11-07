/**
 * Touba Lyon 2026 - Protection des photos
 *
 * Décourage le téléchargement des photos dans toute l'application :
 *  - pas de menu contextuel (clic droit, appui long) sur une image ;
 *  - pas de glisser-déposer d'une image vers le bureau ou un autre onglet ;
 *  - pas d'ouverture de l'image seule dans un nouvel onglet (clic du milieu, Ctrl/Cmd + clic).
 * Les photos restent visibles et agrandissables (popup). Ce n'est pas une protection absolue :
 * une capture d'écran reste possible, comme sur tout site.
 */
(function () {
    if (window.__photoProtect) { return; }
    window.__photoProtect = true;

    var EXT = /\.(jpe?g|png|gif|webp|avif|bmp)(\?|#|$)/i;

    function estImage(el) {
        return !!el && el.tagName === 'IMG';
    }
    function lienImage(el) {
        var a = el && el.closest ? el.closest('a[href]') : null;
        return a && EXT.test(a.getAttribute('href') || '') ? a : null;
    }

    // Clic droit / appui long sur une image
    document.addEventListener('contextmenu', function (e) {
        if (estImage(e.target) || lienImage(e.target)) { e.preventDefault(); }
    }, true);

    // Glisser une image
    document.addEventListener('dragstart', function (e) {
        if (estImage(e.target) || lienImage(e.target)) { e.preventDefault(); }
    }, true);

    // Clic du milieu sur une image ou un lien vers une image : pas de nouvel onglet
    document.addEventListener('auxclick', function (e) {
        if (e.button === 1 && (estImage(e.target) || lienImage(e.target))) { e.preventDefault(); }
    }, true);

    // Ctrl / Cmd + clic : pas de nouvel onglet avec l'image seule
    document.addEventListener('click', function (e) {
        if ((e.ctrlKey || e.metaKey || e.shiftKey) && (estImage(e.target) || lienImage(e.target))) { e.preventDefault(); }
    }, true);

    // Images ajoutées après coup : non « glissables »
    function marquer(racine) {
        var imgs = (racine && racine.querySelectorAll) ? racine.querySelectorAll('img:not([draggable])') : [];
        for (var i = 0; i < imgs.length; i++) { imgs[i].setAttribute('draggable', 'false'); }
        if (racine && racine.tagName === 'IMG' && !racine.hasAttribute('draggable')) { racine.setAttribute('draggable', 'false'); }
    }
    function init() { marquer(document); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
    document.addEventListener('soft:updated', init);
    if (window.MutationObserver) {
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                for (var j = 0; j < muts[i].addedNodes.length; j++) {
                    var n = muts[i].addedNodes[j];
                    if (n.nodeType === 1) { marquer(n); }
                }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
