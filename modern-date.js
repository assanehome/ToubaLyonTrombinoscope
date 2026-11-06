/**
 * Touba Lyon 2026 - Calendrier moderne de sélection de date (et de mois).
 *
 * Remplace le sélecteur natif des <input type="date"> et <input type="month"> :
 *  - champ au style de l'application, date lisible (« lun. 15 nov. 2026 ») ;
 *  - calendrier français (semaine commençant le lundi), navigation par mois / année ;
 *  - « Aujourd'hui », « Effacer » (si le champ n'est pas obligatoire), min / max respectés ;
 *  - ordinateur : fenêtre sous le champ ; mobile : volet qui monte du bas de l'écran.
 * L'<input> d'origine reste dans le formulaire (valeur AAAA-MM-JJ, « required », min, max, événements
 * input / change inchangés), y compris pour les champs ajoutés dynamiquement.
 * Désactivation sur un champ : data-mdate="off".
 */
(function () {
    var MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    var MOIS_C = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    var JOURS_C = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    var JOURS_1 = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function aujourdhui() { var t = new Date(); return iso(t.getFullYear(), t.getMonth(), t.getDate()); }
    function parseDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
        return m ? { y: +m[1], m: +m[2] - 1, d: +m[3] } : null;
    }
    function parseMois(s) {
        var m = /^(\d{4})-(\d{2})$/.exec(s || '');
        return m ? { y: +m[1], m: +m[2] - 1 } : null;
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function texte(input) {
        var v = input.value;
        if (input.type === 'month' || input.dataset.mdateMode === 'month') {
            var pm = parseMois(v);
            return pm ? MOIS[pm.m] + ' ' + pm.y : '';
        }
        var p = parseDate(v);
        if (!p) return '';
        var jour = (new Date(p.y, p.m, p.d).getDay() + 6) % 7;
        return JOURS_C[jour] + ' ' + p.d + ' ' + MOIS_C[p.m] + ' ' + p.y;
    }

    /* ------------------------------------------------------------------ champ */
    function build(input) {
        if (input.dataset.mdateReady || input.dataset.mdate === 'off') return;
        if (input.closest('.mdate')) return;
        input.dataset.mdateReady = '1';
        input.dataset.mdateMode = input.type === 'month' ? 'month' : 'date';

        var wrap = document.createElement('div');
        wrap.className = 'mdate';
        // reprend la mise en page éventuelle du champ d'origine (largeur, flex…)
        ['width', 'maxWidth', 'minWidth', 'flex', 'margin'].forEach(function (k) { if (input.style[k]) { wrap.style[k] = input.style[k]; } });
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('mdate-native');
        input.setAttribute('tabindex', '-1');
        input.setAttribute('aria-hidden', 'true');

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'form-input mdate-btn';
        btn.setAttribute('aria-haspopup', 'dialog');
        btn.innerHTML = '<span class="mdate-ico">📅</span><span class="mdate-txt"></span><span class="mdate-chev">▾</span>';
        wrap.appendChild(btn);
        rafraichir(wrap);
    }

    function rafraichir(wrap) {
        var input = wrap.querySelector('.mdate-native');
        var btn = wrap.querySelector('.mdate-btn');
        if (!input || !btn) return;
        var t = texte(input);
        var span = btn.querySelector('.mdate-txt');
        if (t) { span.textContent = t; span.classList.remove('mdate-ph'); }
        else { span.textContent = input.dataset.mdateMode === 'month' ? 'Choisir un mois' : 'Choisir une date'; span.classList.add('mdate-ph'); }
        wrap.dataset.v = input.value;
        btn.disabled = !!input.disabled;
        wrap.classList.toggle('mdate-off', !!input.disabled);
    }

    /* --------------------------------------------------------------- calendrier */
    var P = null; // calendrier ouvert : { input, wrap, overlay, panel, mode, vue, y, m, curseur }

    function limites(input) {
        return { min: input.getAttribute('min') || '', max: input.getAttribute('max') || '' };
    }

    function ouvrir(wrap) {
        var input = wrap.querySelector('.mdate-native');
        if (!input || input.disabled) return;
        fermer();
        var mode = input.dataset.mdateMode;
        var cur = mode === 'month' ? parseMois(input.value) : parseDate(input.value);
        var base = cur || parseDate(aujourdhui());
        // sans valeur : on se place sur le mois autorisé le plus proche
        if (!cur) {
            var lim = limites(input), t = aujourdhui();
            var ref = lim.min && t < lim.min ? lim.min : (lim.max && t > lim.max ? lim.max : t);
            base = parseDate(ref.length === 7 ? ref + '-01' : ref) || base;
        }
        var overlay = document.createElement('div');
        overlay.className = 'mdp-overlay';
        var panel = document.createElement('div');
        panel.className = 'mdp';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', mode === 'month' ? 'Choisir un mois' : 'Choisir une date');
        panel.tabIndex = -1;
        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        document.documentElement.classList.add('mdp-open');

        P = { input: input, wrap: wrap, overlay: overlay, panel: panel, mode: mode, vue: mode === 'month' ? 'mois' : 'jours',
              y: base.y, m: base.m, curseur: cur && cur.d ? iso(cur.y, cur.m, cur.d) : iso(base.y, base.m, base.d || 1) };
        dessiner();
        placer();
        panel.focus({ preventScroll: true });

        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { fermer(); } });
        overlay.addEventListener('touchstart', function (e) { if (e.target === overlay) { fermer(); } }, { passive: true });
    }

    function fermer() {
        if (!P) return;
        var p = P; P = null;
        if (p.overlay.parentNode) { p.overlay.parentNode.removeChild(p.overlay); }
        document.documentElement.classList.remove('mdp-open');
        try { p.wrap.querySelector('.mdate-btn').focus({ preventScroll: true }); } catch (e) { /* ignoré */ }
    }

    function placer() {
        if (!P) return;
        var panel = P.panel;
        panel.style.left = ''; panel.style.top = ''; panel.style.bottom = '';
        if (window.innerWidth <= 640) { P.overlay.classList.add('mdp-sheet'); return; }
        P.overlay.classList.remove('mdp-sheet');
        var r = P.wrap.getBoundingClientRect();
        var pw = panel.offsetWidth, ph = panel.offsetHeight;
        var left = Math.min(Math.max(8, r.left), window.innerWidth - pw - 8);
        var top = r.bottom + 6;
        if (top + ph > window.innerHeight - 8) {
            var haut = r.top - ph - 6;
            top = haut >= 8 ? haut : Math.max(8, window.innerHeight - ph - 8);
        }
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    function choisir(valeur) {
        if (!P) return;
        var input = P.input;
        input.value = valeur;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        var wrap = P.wrap;
        fermer();
        rafraichir(wrap);
    }

    function dessiner() {
        if (!P) return;
        var lim = limites(P.input);
        var requis = P.input.required;
        var sel = P.input.value;
        var html = '';

        if (P.vue === 'jours') {
            html += entete('<button type="button" class="mdp-titre" data-act="vue-mois">' + MOIS[P.m] + ' ' + P.y + ' ▾</button>', 'mois');
            html += '<div class="mdp-semaine">' + JOURS_1.map(function (j) { return '<span>' + j + '</span>'; }).join('') + '</div><div class="mdp-grille">';
            var premier = (new Date(P.y, P.m, 1).getDay() + 6) % 7;
            var nbJours = new Date(P.y, P.m + 1, 0).getDate();
            var t = aujourdhui();
            for (var c = 0; c < 42; c++) {
                var d = c - premier + 1, dt = new Date(P.y, P.m, d);
                var v = iso(dt.getFullYear(), dt.getMonth(), dt.getDate());
                var cls = 'mdp-j';
                if (d < 1 || d > nbJours) cls += ' mdp-hors';
                if (v === t) cls += ' mdp-auj';
                if (v === sel) cls += ' mdp-sel';
                if (v === P.curseur) cls += ' mdp-cur';
                var off = (lim.min && v < lim.min) || (lim.max && v > lim.max);
                if (off) cls += ' mdp-off';
                html += '<button type="button" class="' + cls + '" data-v="' + v + '"' + (off ? ' disabled' : '') + ' tabindex="-1">' + dt.getDate() + '</button>';
            }
            html += '</div>';
        } else if (P.vue === 'mois') {
            html += entete('<button type="button" class="mdp-titre" data-act="vue-annees">' + P.y + ' ▾</button>', 'annee');
            html += '<div class="mdp-grille-m">';
            var cm = P.mode === 'month' ? parseMois(sel) : null;
            for (var i = 0; i < 12; i++) {
                var vm = P.y + '-' + pad(i + 1);
                var offM = (lim.min && vm < lim.min.slice(0, 7)) || (lim.max && vm > lim.max.slice(0, 7));
                var cl = 'mdp-m' + (cm && cm.y === P.y && cm.m === i ? ' mdp-sel' : '') + (offM ? ' mdp-off' : '');
                if (P.y === new Date().getFullYear() && i === new Date().getMonth()) cl += ' mdp-auj';
                html += '<button type="button" class="' + cl + '" data-m="' + i + '"' + (offM ? ' disabled' : '') + ' tabindex="-1">' + MOIS_C[i].replace('.', '') + '</button>';
            }
            html += '</div>';
        } else {
            var debut = P.y - (P.y % 12);
            html += entete('<span class="mdp-titre mdp-titre-fixe">' + debut + ' – ' + (debut + 11) + '</span>', 'plage');
            html += '<div class="mdp-grille-m">';
            for (var y = debut; y < debut + 12; y++) {
                var offY = (lim.min && String(y) < lim.min.slice(0, 4)) || (lim.max && String(y) > lim.max.slice(0, 4));
                html += '<button type="button" class="mdp-m' + (y === +sel.slice(0, 4) ? ' mdp-sel' : '') + (offY ? ' mdp-off' : '') + '" data-y="' + y + '"' + (offY ? ' disabled' : '') + ' tabindex="-1">' + y + '</button>';
            }
            html += '</div>';
        }

        html += '<div class="mdp-pied">'
            + '<button type="button" class="mdp-lien" data-act="auj">' + (P.mode === 'month' ? 'Ce mois-ci' : "Aujourd'hui") + '</button>'
            + (requis ? '' : '<button type="button" class="mdp-lien mdp-eff" data-act="eff">Effacer</button>')
            + '<button type="button" class="mdp-lien mdp-ferme" data-act="fermer">Fermer</button></div>';
        P.panel.innerHTML = html;
    }

    function entete(milieu, pas) {
        return '<div class="mdp-tete"><button type="button" class="mdp-nav" data-nav="-' + pas + '" aria-label="Précédent">‹</button>'
            + milieu + '<button type="button" class="mdp-nav" data-nav="+' + pas + '" aria-label="Suivant">›</button></div>';
    }

    function naviguer(code) {
        var sens = code.charAt(0) === '-' ? -1 : 1, pas = code.slice(1);
        if (pas === 'mois') { var d = new Date(P.y, P.m + sens, 1); P.y = d.getFullYear(); P.m = d.getMonth(); }
        else if (pas === 'annee') { P.y += sens; }
        else { P.y += sens * 12; }
        dessiner();
    }

    // Clics dans le calendrier
    document.addEventListener('click', function (e) {
        var t = e.target;
        // ouverture depuis le champ
        var btn = t.closest && t.closest('.mdate-btn');
        if (btn) { e.preventDefault(); var w = btn.closest('.mdate'); if (P && P.wrap === w) { fermer(); } else { ouvrir(w); } return; }
        if (!P || !P.panel.contains(t)) return;
        var el = t.closest('button');
        if (!el) return;
        if (el.dataset.nav) { naviguer(el.dataset.nav); return; }
        if (el.dataset.v) { choisir(el.dataset.v); return; }
        if (el.dataset.m !== undefined) {
            P.m = +el.dataset.m;
            if (P.mode === 'month') { choisir(P.y + '-' + pad(P.m + 1)); } else { P.vue = 'jours'; dessiner(); }
            return;
        }
        if (el.dataset.y) { P.y = +el.dataset.y; P.vue = 'mois'; dessiner(); return; }
        var act = el.dataset.act;
        if (act === 'vue-mois') { P.vue = 'mois'; dessiner(); }
        else if (act === 'vue-annees') { P.vue = 'annees'; dessiner(); }
        else if (act === 'auj') {
            var a = aujourdhui(), lim = limites(P.input);
            if ((lim.min && a < lim.min) || (lim.max && a > lim.max)) { return; }
            choisir(P.mode === 'month' ? a.slice(0, 7) : a);
        }
        else if (act === 'eff') { choisir(''); }
        else if (act === 'fermer') { fermer(); }
    });

    // Clavier : flèches pour se déplacer, Entrée pour choisir, Échap pour fermer
    document.addEventListener('keydown', function (e) {
        if (!P) return;
        if (e.key === 'Escape') { e.preventDefault(); fermer(); return; }
        if (P.vue !== 'jours') return;
        var p = parseDate(P.curseur), pas = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        if (pas) {
            e.preventDefault();
            var d = new Date(p.y, p.m, p.d + pas);
            P.curseur = iso(d.getFullYear(), d.getMonth(), d.getDate());
            P.y = d.getFullYear(); P.m = d.getMonth();
            dessiner();
        } else if (e.key === 'PageUp' || e.key === 'PageDown') {
            e.preventDefault();
            var d2 = new Date(p.y, p.m + (e.key === 'PageUp' ? -1 : 1), 1);
            P.y = d2.getFullYear(); P.m = d2.getMonth(); P.curseur = iso(P.y, P.m, 1);
            dessiner();
        } else if (e.key === 'Enter') {
            var lim = limites(P.input);
            if ((lim.min && P.curseur < lim.min) || (lim.max && P.curseur > lim.max)) { return; }
            e.preventDefault();
            choisir(P.curseur);
        }
    });

    window.addEventListener('resize', function () { if (P) { placer(); } });
    window.addEventListener('scroll', function () { if (P && window.innerWidth > 640) { placer(); } }, true);

    // Validation native (champ obligatoire vide…) : le champ visible passe en rouge
    document.addEventListener('invalid', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('mdate-native')) {
            var b = t.closest('.mdate').querySelector('.mdate-btn');
            if (b) { b.classList.add('mdate-invalide'); }
        }
    }, true);
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('mdate-native')) {
            var w = t.closest('.mdate');
            if (w) { rafraichir(w); w.querySelector('.mdate-btn').classList.remove('mdate-invalide'); }
        }
    }, true);

    /* ------------------------------------------------------------- initialisation */
    function tout(racine) {
        var l = (racine || document).querySelectorAll('input[type="date"], input[type="month"]');
        for (var i = 0; i < l.length; i++) { build(l[i]); }
    }

    function init() {
        tout(document);
        // champs ajoutés plus tard (formulaires créés en JavaScript)
        if (window.MutationObserver) {
            new MutationObserver(function (muts) {
                for (var i = 0; i < muts.length; i++) {
                    for (var j = 0; j < muts[i].addedNodes.length; j++) {
                        var n = muts[i].addedNodes[j];
                        if (n.nodeType !== 1) continue;
                        if (n.matches && n.matches('input[type="date"], input[type="month"]')) { build(n); }
                        else if (n.querySelectorAll) { tout(n); }
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
        // valeurs modifiées par programme (input.value = …) ou remise à zéro du formulaire
        setInterval(function () {
            if (document.hidden) return;
            var ws = document.querySelectorAll('.mdate');
            for (var i = 0; i < ws.length; i++) {
                var inp = ws[i].querySelector('.mdate-native');
                if (inp && (ws[i].dataset.v !== inp.value || ws[i].classList.contains('mdate-off') !== !!inp.disabled)) { rafraichir(ws[i]); }
            }
        }, 400);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
