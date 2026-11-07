<?php /* Touba Lyon 2026 - Popup d'agrandissement des photos (miniatures) partagé sur toute l'application. */ ?>
<!-- Popup photo (lightbox) — clic sur une miniature pour l'afficher en grand -->
<style>
    #photo-lightbox {
        position: fixed;
        inset: 0;
        z-index: 100000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 4vw;
        background: rgba(4, 14, 10, 0.88);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        opacity: 0;
        transition: opacity 0.25s ease;
        cursor: zoom-out;
    }
    #photo-lightbox.open { display: flex; opacity: 1; }
    #photo-lightbox .plb-inner {
        position: relative;
        max-width: 92vw;
        max-height: 88vh;
        transform: scale(0.92);
        transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
    }
    #photo-lightbox.open .plb-inner { transform: scale(1); }
    #photo-lightbox img {
        display: block;
        max-width: 92vw;
        max-height: 88vh;
        width: auto;
        height: auto;
        border-radius: 12px;
        border: 2px solid rgba(212, 175, 55, 0.5);
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.6);
        background: #0c241a;
        object-fit: contain;
    }
    #photo-lightbox .plb-close {
        position: absolute;
        top: -14px; right: -14px;
        width: 40px; height: 40px;
        border-radius: 50%;
        border: 1px solid rgba(212, 175, 55, 0.6);
        background: linear-gradient(135deg, #d4af37, #f1d279);
        color: #0c241a;
        font-size: 1.3rem;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
    }
    @media (max-width: 600px) {
        #photo-lightbox .plb-close { top: -8px; right: -8px; width: 36px; height: 36px; }
    }
    /* Signale que les miniatures sont cliquables */
    img[src*="uploads/"], .photo-zoomable { cursor: zoom-in; }
</style>
<div id="photo-lightbox" role="dialog" aria-modal="true" aria-label="Photo agrandie">
    <div class="plb-inner">
        <button type="button" class="plb-close" id="plb-close" aria-label="Fermer">✕</button>
        <img id="plb-img" src="" alt="Photo agrandie">
    </div>
</div>
<script>
    // Popup d'agrandissement des photos (miniatures) — sur toute l'application
    (function () {
        if (window.__plbInit) { return; } window.__plbInit = true;
        var box = document.getElementById('photo-lightbox');
        var img = document.getElementById('plb-img');
        var closeBtn = document.getElementById('plb-close');
        if (!box || !img) { return; }

        function isZoomable(el) {
            if (!el || el.tagName !== 'IMG') { return false; }
            if (el.closest('#photo-lightbox')) { return false; }   // pas l'image du popup elle-même
            if (el.classList.contains('no-zoom')) { return false; } // opt-out explicite
            if (el.classList.contains('photo-zoomable')) { return true; }
            var src = el.getAttribute('src') || '';
            return src.indexOf('uploads/') !== -1;                 // photos des membres
        }
        function open(src) {
            if (!src) { return; }
            img.setAttribute('src', src);
            box.style.display = 'flex';
            requestAnimationFrame(function () { box.classList.add('open'); });
            document.body.style.overflow = 'hidden';
        }
        function close() {
            box.classList.remove('open');
            document.body.style.overflow = '';
            setTimeout(function () { box.style.display = 'none'; img.setAttribute('src', ''); }, 260);
        }

        // Clic sur une miniature n'importe où dans la page (délégation)
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!isZoomable(t)) { return; }
            // Utilise la version haute résolution si disponible (data-full), sinon le src affiché
            var full = t.getAttribute('data-full') || t.getAttribute('src');
            e.preventDefault();
            e.stopPropagation();
            open(full);
        }, true);

        box.addEventListener('click', function (e) { if (e.target === box || e.target.classList.contains('plb-inner')) { close(); } });
        if (closeBtn) { closeBtn.addEventListener('click', close); }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box.classList.contains('open')) { close(); } });
    })();
</script>
