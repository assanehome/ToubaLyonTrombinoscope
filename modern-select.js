/**
 * Touba Lyon 2026 - Liste déroulante moderne (custom select)
 *
 * Transforme tout <select class="modern-select"> en un dropdown stylé et animé,
 * tout en conservant le <select> natif (caché) pour l'envoi du formulaire et la
 * validation HTML. Accessible au clavier (flèches, Entrée, Échap) et au clic extérieur.
 */
(function () {
    function build(select) {
        if (select.dataset.mselReady) return;
        select.dataset.mselReady = '1';

        // Conteneur
        var wrap = document.createElement('div');
        wrap.className = 'msel';
        select.parentNode.insertBefore(wrap, select);
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

        // Panneau d'options
        var panel = document.createElement('div');
        panel.className = 'msel-panel';
        panel.setAttribute('role', 'listbox');
        wrap.appendChild(panel);

        var optEls = [];
        Array.prototype.forEach.call(select.options, function (opt, i) {
            var o = document.createElement('div');
            o.className = 'msel-option';
            o.setAttribute('role', 'option');
            o.dataset.index = i;
            var txt = document.createElement('span');
            txt.textContent = opt.textContent;
            var chk = document.createElement('span');
            chk.className = 'msel-check';
            chk.textContent = '✓';
            o.appendChild(txt);
            o.appendChild(chk);
            o.addEventListener('click', function () { choose(i); });
            panel.appendChild(o);
            optEls.push(o);
        });

        var activeIndex = 0;

        function syncLabel() {
            var sel = select.options[select.selectedIndex];
            var isPlaceholder = (!sel || sel.value === '');
            label.textContent = sel ? sel.textContent : '';
            trigger.classList.toggle('placeholder', isPlaceholder);
            optEls.forEach(function (el, i) {
                el.classList.toggle('selected', i === select.selectedIndex);
            });
        }
        function highlight() {
            optEls.forEach(function (el, i) { el.classList.toggle('active', i === activeIndex); });
            if (optEls[activeIndex]) optEls[activeIndex].scrollIntoView({ block: 'nearest' });
        }
        function open() {
            wrap.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            activeIndex = select.selectedIndex < 0 ? 0 : select.selectedIndex;
            highlight();
            document.addEventListener('click', outside);
            document.addEventListener('keydown', onKey);
        }
        function close() {
            wrap.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', outside);
            document.removeEventListener('keydown', onKey);
        }
        function choose(i) {
            select.selectedIndex = i;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncLabel();
            close();
            trigger.focus();
        }
        function outside(e) { if (!wrap.contains(e.target)) close(); }
        function onKey(e) {
            if (e.key === 'Escape') { close(); trigger.focus(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(optEls.length - 1, activeIndex + 1); highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(0, activeIndex - 1); highlight(); }
            else if (e.key === 'Enter') { e.preventDefault(); choose(activeIndex); }
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (wrap.classList.contains('open')) { close(); } else { open(); }
        });
        // Si la valeur du select change ailleurs (reset, JS), on resynchronise.
        select.addEventListener('change', syncLabel);

        syncLabel();
    }

    function init() {
        var list = document.querySelectorAll('select.modern-select');
        Array.prototype.forEach.call(list, build);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
