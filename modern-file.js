/**
 * Touba Lyon 2026 - Sélection de photo / fichier moderne, adaptée au mobile.
 *
 * Transforme tout <input type="file"> « natif » en grande zone tactile :
 *  - sur mobile : « Prendre ou choisir une photo » (appareil photo ou galerie) ;
 *  - sur ordinateur : clic ou glisser-déposer ;
 *  - aperçu de l'image choisie (ou de toutes les images d'un album), nom et poids du fichier,
 *    bouton ✕ pour retirer la sélection.
 * L'<input> d'origine reste dans le formulaire (envoi et validation « required » inchangés).
 *
 * Exclusions : data-mfile="off", champs déjà personnalisés (.file-upload-wrapper, .photo-tile)
 * et champs volontairement masqués (display:none, pilotés par un bouton propre à la page).
 */
(function () {
    var tactile = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

    function kind(input) {
        var a = (input.getAttribute('accept') || '').toLowerCase();
        if (a.indexOf('image') !== -1) return 'image';
        if (a.indexOf('pdf') !== -1) return 'pdf';
        if (a.indexOf('audio') !== -1) return 'audio';
        return 'file';
    }

    var TEXTES = {
        image: { icone: '📷', un: tactile ? 'Prendre ou choisir une photo' : 'Choisir une image', plusieurs: tactile ? 'Prendre ou choisir des photos' : 'Choisir des images', aide: tactile ? 'Appareil photo ou galerie' : 'Cliquez ou glissez-déposez' },
        pdf:   { icone: '📄', un: 'Choisir un document PDF', plusieurs: 'Choisir des documents PDF', aide: tactile ? 'Touchez pour parcourir vos fichiers' : 'Cliquez ou glissez-déposez' },
        audio: { icone: '🎵', un: 'Choisir un fichier audio', plusieurs: 'Choisir des fichiers audio', aide: tactile ? 'Touchez pour parcourir vos fichiers' : 'Cliquez ou glissez-déposez' },
        file:  { icone: '📎', un: 'Choisir un fichier', plusieurs: 'Choisir des fichiers', aide: tactile ? 'Touchez pour parcourir vos fichiers' : 'Cliquez ou glissez-déposez' }
    };

    function taille(o) {
        if (o < 1024) return o + ' o';
        if (o < 1048576) return Math.round(o / 1024) + ' Ko';
        return (o / 1048576).toFixed(1).replace('.', ',') + ' Mo';
    }

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }

    function build(input) {
        if (input.dataset.mfileReady) return;
        if (input.dataset.mfile === 'off') return;
        if (input.closest('.file-upload-wrapper, .photo-tile, .mfile')) return;
        if (input.style.display === 'none' || input.hidden) return;

        input.dataset.mfileReady = '1';
        var k = kind(input);
        var T = TEXTES[k];
        var multiple = input.multiple;

        var wrap = document.createElement('label');
        wrap.className = 'mfile';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('mfile-input');

        var vide = document.createElement('div');
        vide.className = 'mfile-empty';
        vide.innerHTML = '<span class="mfile-ico">' + T.icone + '</span>'
            + '<span class="mfile-txt"><strong>' + (multiple ? T.plusieurs : T.un) + '</strong><small>' + T.aide + '</small></span>'
            + '<span class="mfile-plus">+</span>';
        wrap.appendChild(vide);

        var plein = document.createElement('div');
        plein.className = 'mfile-full';
        plein.style.display = 'none';
        wrap.appendChild(plein);

        var urls = [];
        function libere() { urls.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) { /* ignoré */ } }); urls = []; }

        function rafraichir() {
            libere();
            var f = input.files ? Array.prototype.slice.call(input.files) : [];
            if (!f.length) {
                plein.style.display = 'none';
                plein.innerHTML = '';
                vide.style.display = '';
                wrap.classList.remove('mfile-has');
                return;
            }
            var html = '';
            if (k === 'image') {
                html += '<div class="mfile-thumbs">';
                f.slice(0, 12).forEach(function (fi) {
                    var u = URL.createObjectURL(fi); urls.push(u);
                    html += '<img src="' + u + '" alt="" class="' + (f.length === 1 ? 'mfile-big' : 'mfile-mini') + '">';
                });
                html += '</div>';
            }
            var nom = f.length === 1 ? esc(f[0].name) : f.length + ' fichiers sélectionnés';
            var poids = f.reduce(function (s, x) { return s + x.size; }, 0);
            html += '<div class="mfile-bar"><span class="mfile-ok">✓</span>'
                + '<span class="mfile-name">' + nom + '<small>' + taille(poids) + (f.length === 1 ? '' : ' au total') + ' · Touchez pour changer</small></span>'
                + '<button type="button" class="mfile-clear" aria-label="Retirer la sélection">✕</button></div>';
            plein.innerHTML = html;
            plein.style.display = '';
            vide.style.display = 'none';
            wrap.classList.add('mfile-has');
            var b = plein.querySelector('.mfile-clear');
            if (b) {
                b.addEventListener('click', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    input.value = '';
                    rafraichir();
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
        }

        input.addEventListener('change', rafraichir);
        var form = input.form;
        if (form) { form.addEventListener('reset', function () { setTimeout(rafraichir, 0); }); }

        // Glisser-déposer (ordinateur)
        ['dragenter', 'dragover'].forEach(function (ev) {
            wrap.addEventListener(ev, function (e) { e.preventDefault(); wrap.classList.add('mfile-drag'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            wrap.addEventListener(ev, function (e) { e.preventDefault(); wrap.classList.remove('mfile-drag'); });
        });
        wrap.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                try {
                    input.files = e.dataTransfer.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (err) { /* navigateur sans prise en charge : sélection classique */ }
            }
        });

        rafraichir();
    }

    function tout() {
        var l = document.querySelectorAll('input[type="file"]');
        for (var i = 0; i < l.length; i++) build(l[i]);
    }

    // Textes adaptés au tactile pour les zones de photo déjà personnalisées (inscription, profil…)
    function zonesExistantes() {
        if (!tactile) return;
        var t = document.getElementById('upload-text');
        if (t && /parcourez/i.test(t.textContent)) {
            t.innerHTML = 'Touchez pour <span>prendre ou choisir une photo</span>';
        }
    }

    function init() { tout(); zonesExistantes(); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
    document.addEventListener('soft:updated', tout);
})();
