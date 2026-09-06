<?php /* Touba Lyon 2026 - Actualisation par glissement vers le bas (pull-to-refresh), partagée sur toutes les pages. */ ?>
<!-- Indicateur de tirage pour actualiser (pull-to-refresh moderne) -->
<style>
    #ptr {
        position: fixed;
        top: 0; left: 0; right: 0;
        height: 64px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        color: var(--white);
        font-size: 0.9rem;
        font-weight: 600;
        letter-spacing: 0.01em;
        background: linear-gradient(180deg, rgba(12,36,26,0.98), rgba(27,67,50,0.92));
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-bottom: 1px solid rgba(212,175,55,0.25);
        box-shadow: 0 8px 30px rgba(0,0,0,0.45);
        transform: translateY(-100%);
        transition: transform 0.28s cubic-bezier(0.22,1,0.36,1);
        z-index: 9999;
        pointer-events: none;
    }
    #ptr::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent);
        background-size: 200% 100%;
        animation: ptrFlow 3.5s linear infinite;
    }
    @keyframes ptrFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    #ptr-spin {
        width: 22px; height: 22px;
        border: 2.5px solid rgba(212,175,55,0.3);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: ptrSpin 0.7s linear infinite;
        transition: opacity 0.2s ease, transform 0.2s ease;
    }
    #ptr-spin.idle { opacity: 0; transform: rotate(-90deg); }
    #ptr-spin.ready { animation-duration: 0.45s; border-top-color: #f1d279; }
    @keyframes ptrSpin { to { transform: rotate(360deg); } }
    #ptr-arrow { font-size: 1.15rem; transition: transform 0.25s ease; }
    #ptr-arrow.ready { transform: rotate(180deg); }
</style>
<div id="ptr">
    <span id="ptr-spin" class="idle"></span>
    <span id="ptr-arrow">⬇️</span>
    <span id="ptr-text">Tirez pour actualiser</span>
</div>
<script>
    // Actualisation par glissement vers le bas en haut de page (pull-to-refresh moderne)
    (function () {
        if (window.__ptrInit) { return; } window.__ptrInit = true;   // éviter tout doublon
        var bar = document.getElementById('ptr');
        var spin = document.getElementById('ptr-spin');
        var arrow = document.getElementById('ptr-arrow');
        var text = document.getElementById('ptr-text');
        if (!bar || !spin || !arrow || !text) { return; }
        var startY = 0, pulling = false, threshold = 90;
        function setReady(on) {
            spin.classList.toggle('ready', on);
            spin.classList.toggle('idle', !on);
            arrow.classList.toggle('ready', on);
        }
        function reset() {
            bar.style.transform = 'translateY(-100%)';
            pulling = false;
            setReady(false);
            text.textContent = 'Tirez pour actualiser';
        }
        document.addEventListener('touchstart', function (e) {
            if (window.scrollY > 0) { return; }
            startY = e.touches[0].clientY;
            pulling = true;
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (!pulling || window.scrollY > 0) { return; }
            var dy = e.touches[0].clientY - startY;
            if (dy <= 0) { reset(); return; }
            dy = Math.min(dy, 150);
            bar.style.transform = 'translateY(' + (dy - 64) + 'px)';
            if (dy >= threshold) {
                setReady(true);
                text.textContent = 'Relâchez pour actualiser';
            } else {
                setReady(false);
                text.textContent = 'Tirez pour actualiser';
            }
        }, { passive: true });
        document.addEventListener('touchend', function () {
            if (!pulling) { return; }
            var dy = 0;
            var m = bar.style.transform.match(/(-?\d+(?:\.\d+)?)px/);
            if (m) { dy = parseFloat(m[1]) + 64; }
            if (dy >= threshold) {
                setReady(true);
                text.textContent = 'Actualisation…';
                window.location.reload();
                return;
            }
            reset();
        });
    })();
</script>
