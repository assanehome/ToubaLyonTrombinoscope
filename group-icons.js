/**
 * Touba Lyon 2026 - Icône des groupes repliables (pastille ronde à gauche du titre)
 *
 * Reprend l'émoji qui commence le titre d'un groupe (<summary>) et l'enveloppe dans une pastille
 * (.grp-ic, mise en forme par style.css). Sans émoji, une icône par défaut est ajoutée.
 * Exclus : les groupes marqués .no-group, .sd-votants, .kav-message, .mfile.
 */
(function () {
    if (window.__grpIcons) { return; }
    window.__grpIcons = true;

    var EXCLUS = '.no-group, .sd-votants, .kav-message, .mfile';
    var DEFAUT = '📁';
    var DEBUT = /^\s*((?:\p{Extended_Pictographic}|\p{Regional_Indicator})(?:️|‍\p{Extended_Pictographic}|\p{Emoji_Modifier})*)\s*/u;
    var SEUL = /^\s*(?:\p{Extended_Pictographic})(?:️|‍\p{Extended_Pictographic}|\p{Emoji_Modifier})*\s*$/u;

    function premierUtile(s) {
        for (var n = s.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3 && n.nodeValue.trim() === '') { continue; }
            if (n.nodeType === 8) { continue; }
            return n;
        }
        return null;
    }

    function decorer(s) {
        if (s.querySelector(':scope > .grp-ic')) { return; }
        var d = s.parentNode;
        if (!d || d.tagName !== 'DETAILS' || d.matches(EXCLUS) || !d.closest('main')) { return; }
        var n = premierUtile(s);
        // 1. déjà dans un <span> : on réutilise l'élément comme pastille
        if (n && n.nodeType === 1 && SEUL.test(n.textContent || '')) {
            n.classList.add('grp-ic');
            n.style.removeProperty('font-size');
            return;
        }
        // 2. émoji au début du texte : on l'extrait dans une pastille
        if (n && n.nodeType === 3) {
            var m = DEBUT.exec(n.nodeValue);
            if (m) {
                var b = document.createElement('span');
                b.className = 'grp-ic';
                b.setAttribute('aria-hidden', 'true');
                b.textContent = m[1];
                n.nodeValue = n.nodeValue.slice(m[0].length);
                s.insertBefore(b, n);
                return;
            }
        }
        // 3. pas d'émoji : icône par défaut
        var p = document.createElement('span');
        p.className = 'grp-ic';
        p.setAttribute('aria-hidden', 'true');
        p.textContent = DEFAUT;
        s.insertBefore(p, s.firstChild);
    }

    function tout(racine) {
        var liste = (racine && racine.querySelectorAll ? racine : document).querySelectorAll('main details > summary');
        for (var i = 0; i < liste.length; i++) { decorer(liste[i]); }
    }

    function init() { tout(document); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
    document.addEventListener('soft:updated', init);
    if (window.MutationObserver) {
        var attente = false;
        new MutationObserver(function (muts) {
            var a = false;
            for (var i = 0; i < muts.length && !a; i++) {
                for (var j = 0; j < muts[i].addedNodes.length; j++) {
                    var nd = muts[i].addedNodes[j];
                    if (nd.nodeType === 1 && (nd.tagName === 'DETAILS' || nd.tagName === 'SUMMARY' || (nd.querySelector && nd.querySelector('summary')))) { a = true; break; }
                }
            }
            if (!a || attente) { return; }
            attente = true;
            setTimeout(function () { attente = false; init(); }, 0);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
