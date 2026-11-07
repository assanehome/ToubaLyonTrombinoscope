/**
 * Touba Lyon 2026 - Liste déroulante moderne (custom select) avec recherche
 *
 * Transforme TOUS les <select> simples de l'application (sauf data-native / .native-select / multiple)
 * en un dropdown stylé et animé, tout en conservant le <select> natif (caché) pour l'envoi du
 * formulaire et la validation HTML. Au-delà de 5 options, un champ de recherche (insensible aux
 * accents et à la casse) filtre la liste. Le panneau s'ouvre au-dessus de la page (jamais coupé par
 * une carte ou un groupe) et passe au-dessus du trigger s'il manque de place en bas.
 * Accessible au clavier (flèches, Entrée, Échap) et au clic extérieur.
 * Les listes ajoutées ou modifiées après coup (JS, actions sans rechargement) sont prises en compte.
 */
(function () {
    if (window.__mselLoaded) { return; }
    window.__mselLoaded = true;

    var SEARCH_MIN = 6; // nombre d'options à partir duquel la recherche apparaît

    function norm(s) {
        s = (s || '').toLowerCase();
        try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { /* navigateur ancien */ }
        return s;
    }

    function build(select) {
        if (select.dataset.mselReady) { return; }
        select.dataset.mselReady = '1';

        // Conteneur (reprend la largeur / le flex / les marges du select d'origine)
        var wrap = document.createElement('div');
        wrap.className = 'msel';
        var cs = window.getComputedStyle(select);
        var parent = select.parentNode;
        var pw = parent && parent.clientWidth ? parent.clientWidth : 0;
        if (select.style.width) { wrap.style.width = select.style.width; wrap.style.display = 'inline-block'; wrap.style.verticalAlign = 'middle'; }
        else if (select.offsetWidth > 0 && pw > 0 && select.offsetWidth < pw * 0.9 && parseFloat(cs.flexGrow) === 0) {
            wrap.style.width = select.offsetWidth + 'px'; wrap.style.display = 'inline-block'; wrap.style.verticalAlign = 'middle';
        }
        if (parseFloat(cs.flexGrow) > 0) { wrap.style.flex = cs.flex; }
        if (select.style.maxWidth) { wrap.style.maxWidth = select.style.maxWidth; }
        if (select.style.minWidth || cs.minWidth === '0px') { wrap.style.minWidth = select.style.minWidth || '0'; }
        if (select.style.margin) { wrap.style.margin = select.style.margin; }
        parent.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('msel-native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        // Déclencheur
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'msel-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        var label = document.createElement('span');
        label.className = 'msel-label';
        var arrow = document.createElement('span');
        arrow.className = 'msel-arrow';
        arrow.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
        trigger.appendChild(label);
        trigger.appendChild(arrow);
        wrap.appendChild(trigger);

        // Panneau : champ de recherche + options
        var panel = document.createElement('div');
        panel.className = 'msel-panel';
        panel.setAttribute('role', 'listbox');
        var searchBox = document.createElement('div');
        searchBox.className = 'msel-search';
        var search = document.createElement('input');
        search.type = 'text';
        search.setAttribute('autocomplete', 'off');
        search.setAttribute('autocapitalize', 'off');
        search.setAttribute('spellcheck', 'false');
        search.placeholder = '🔍 Rechercher…';
        searchBox.appendChild(search);
        panel.appendChild(searchBox);
        var list = document.createElement('div');
        list.className = 'msel-list';
        panel.appendChild(list);
        var empty = document.createElement('div');
        empty.className = 'msel-empty';
        empty.textContent = 'Aucun résultat';
        empty.style.display = 'none';
        panel.appendChild(empty);
        wrap.appendChild(panel);

        var optEls = [];
        var activeIndex = 0;
        var isOpen = false;

        function buildOptions() {
            list.innerHTML = '';
            optEls = [];
            Array.prototype.forEach.call(select.options, function (opt, i) {
                var o = document.createElement('div');
                o.className = 'msel-option' + (opt.disabled ? ' disabled' : '');
                o.setAttribute('role', 'option');
                o.dataset.index = i;
                o.dataset.t = norm(opt.textContent);
                var txt = document.createElement('span');
                txt.textContent = opt.textContent;
                var chk = document.createElement('span');
                chk.className = 'msel-check';
                chk.textContent = '✓';
                o.appendChild(txt);
                o.appendChild(chk);
                o.addEventListener('click', function () { if (!opt.disabled) { choose(i); } });
                list.appendChild(o);
                optEls.push(o);
            });
            searchBox.style.display = optEls.length >= SEARCH_MIN ? '' : 'none';
            syncLabel();
        }
        function syncLabel() {
            var sel = select.options[select.selectedIndex];
            var isPlaceholder = (!sel || sel.value === '');
            label.textContent = sel ? sel.textContent : '';
            trigger.classList.toggle('placeholder', isPlaceholder);
            trigger.disabled = !!select.disabled;
            optEls.forEach(function (el, i) { el.classList.toggle('selected', i === select.selectedIndex); });
        }
        function visibles() {
            var r = [];
            optEls.forEach(function (el, i) { if (el.style.display !== 'none' && !el.classList.contains('disabled')) { r.push(i); } });
            return r;
        }
        function highlight(scroll) {
            optEls.forEach(function (el, i) { el.classList.toggle('active', i === activeIndex); });
            if (scroll !== false && optEls[activeIndex]) { optEls[activeIndex].scrollIntoView({ block: 'nearest' }); }
        }
        function filter() {
            var q = norm(search.value.trim());
            var n = 0;
            optEls.forEach(function (el) {
                var ok = (q === '' || el.dataset.t.indexOf(q) !== -1);
                el.style.display = ok ? '' : 'none';
                if (ok) { n++; }
            });
            empty.style.display = n === 0 ? '' : 'none';
            var v = visibles();
            if (v.length && v.indexOf(activeIndex) === -1) { activeIndex = v[0]; }
            highlight();
        }
        function position() {
            var r = trigger.getBoundingClientRect();
            var vh = window.innerHeight || document.documentElement.clientHeight;
            var vw = window.innerWidth || document.documentElement.clientWidth;
            var below = vh - r.bottom - 10;
            var above = r.top - 10;
            var width = Math.min(Math.max(r.width, 180), vw - 16);
            var left = Math.min(Math.max(8, r.left), Math.max(8, vw - width - 8));
            panel.style.position = 'fixed';
            panel.style.left = left + 'px';
            panel.style.right = 'auto';
            panel.style.width = width + 'px';
            if (below >= 220 || below >= above) {
                panel.style.top = (r.bottom + 6) + 'px';
                panel.style.bottom = 'auto';
                panel.style.maxHeight = Math.max(140, Math.min(340, below)) + 'px';
                panel.style.transformOrigin = 'top center';
            } else {
                panel.style.top = 'auto';
                panel.style.bottom = (vh - r.top + 6) + 'px';
                panel.style.maxHeight = Math.max(140, Math.min(340, above)) + 'px';
                panel.style.transformOrigin = 'bottom center';
            }
        }
        function open() {
            if (select.disabled || isOpen) { return; }
            if (window.__mselOpen && window.__mselOpen !== close) { window.__mselOpen(); }
            isOpen = true;
            window.__mselOpen = close;
            document.body.appendChild(panel); // hors des cartes : jamais coupé par un overflow
            panel.style.zIndex = '100000';
            position();
            wrap.classList.add('open');
            panel.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            search.value = '';
            optEls.forEach(function (el) { el.style.display = ''; });
            empty.style.display = 'none';
            activeIndex = select.selectedIndex < 0 ? 0 : select.selectedIndex;
            highlight();
            if (optEls.length >= SEARCH_MIN) { try { search.focus({ preventScroll: true }); } catch (e) { search.focus(); } }
            document.addEventListener('click', outside, true);
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('resize', position);
            window.addEventListener('scroll', onScroll, true);
        }
        function close() {
            if (!isOpen) { return; }
            isOpen = false;
            if (window.__mselOpen === close) { window.__mselOpen = null; }
            wrap.classList.remove('open');
            panel.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', outside, true);
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', onScroll, true);
            setTimeout(function () {
                if (!isOpen) { panel.style.cssText = ''; wrap.appendChild(panel); }
            }, 220);
        }
        function onScroll(e) {
            if (panel.contains(e.target)) { return; }
            var r = trigger.getBoundingClientRect();
            var vh = window.innerHeight || document.documentElement.clientHeight;
            if (r.bottom < 0 || r.top > vh) { close(); } else { position(); }
        }
        function choose(i) {
            select.selectedIndex = i;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncLabel();
            close();
            try { trigger.focus({ preventScroll: true }); } catch (e) { /* ignoré */ }
        }
        function outside(e) { if (!wrap.contains(e.target) && !panel.contains(e.target)) { close(); } }
        function move(delta) {
            var v = visibles();
            if (!v.length) { return; }
            var p = v.indexOf(activeIndex);
            p = p === -1 ? 0 : Math.max(0, Math.min(v.length - 1, p + delta));
            activeIndex = v[p];
            highlight();
        }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); close(); trigger.focus(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter') { e.preventDefault(); if (visibles().indexOf(activeIndex) !== -1) { choose(activeIndex); } }
            else if (e.key === 'Tab') { close(); }
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isOpen) { close(); } else { open(); }
        });
        search.addEventListener('input', filter);
        // Un focus sur le select natif (validation « requis », libellé) bascule sur le déclencheur
        select.addEventListener('focus', function () { try { trigger.focus({ preventScroll: true }); } catch (e) { /* ignoré */ } });
        // Si la valeur du select change ailleurs (reset, JS), on resynchronise.
        select.addEventListener('change', syncLabel);
        if (select.form) { select.form.addEventListener('reset', function () { setTimeout(syncLabel, 0); }); }

        // Options ou état modifiés par le script de la page : on reconstruit
        if (window.MutationObserver) {
            var pending = false;
            new MutationObserver(function () {
                if (pending) { return; }
                pending = true;
                setTimeout(function () { pending = false; buildOptions(); }, 0);
            }).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
        }

        buildOptions();
    }

    function eligible(s) {
        return !s.multiple && !(s.size > 1) && !s.dataset.mselReady
            && !s.hasAttribute('data-native') && !s.classList.contains('native-select')
            && !s.closest('.msel');
    }

    function init() {
        var list = document.querySelectorAll('select');
        Array.prototype.forEach.call(list, function (s) { if (eligible(s)) { build(s); } });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // contenu mis à jour sans rechargement (soft-submit.js) : nouvelles listes à transformer
    document.addEventListener('soft:updated', init);
    // listes créées par le script d'une page (lignes ajoutées dynamiquement, etc.)
    if (window.MutationObserver) {
        var queued = false;
        new MutationObserver(function (muts) {
            var ajout = false;
            for (var i = 0; i < muts.length && !ajout; i++) {
                for (var j = 0; j < muts[i].addedNodes.length; j++) {
                    var n = muts[i].addedNodes[j];
                    if (n.nodeType === 1 && (n.tagName === 'SELECT' || (n.querySelector && n.querySelector('select')))) { ajout = true; break; }
                }
            }
            if (!ajout || queued) { return; }
            queued = true;
            setTimeout(function () { queued = false; init(); }, 0);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
