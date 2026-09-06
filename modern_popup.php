<?php
/**
 * Touba Lyon 2026 - Popups modernes réutilisables
 *
 * Fournit une modale de confirmation et une modale d'alerte au style du site
 * (glass-card), remplaçant les boîtes natives alert() / confirm().
 *
 * À inclure juste avant </body> (une seule fois par page) :
 *   <?php include __DIR__ . '/modern_popup.php'; ?>
 *
 * API JavaScript :
 *   modernConfirm(message, onOk)          -> modale de confirmation
 *   modernAlert(message, titre?)          -> modale d'information
 *   modernDanger(message, onOk, btnLabel) -> confirmation avec bouton danger
 *
 * Les formulaires porteurs de l'attribut data-confirm sont automatiquement
 * interceptés : le submit est remplacé par une confirmation moderne.
 */
if (!defined('MODERN_POPUP_INCLUDED')) {
    define('MODERN_POPUP_INCLUDED', true);
    ?>
    <!-- Modale de confirmation moderne -->
    <div id="mpp-confirm" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:430px; width:calc(100vw - 28px);">
            <div class="modal-header"><h3 id="mpp-confirm-title" style="color:var(--accent);">Confirmer</h3></div>
            <div class="modal-body"><p id="mpp-confirm-msg" style="margin:0;">Voulez-vous continuer ?</p></div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end; flex-wrap:wrap;">
                <button type="button" id="mpp-confirm-cancel" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="mpp-confirm-extra" class="btn btn-sm" style="display:none;"></button>
                <button type="button" id="mpp-confirm-ok" class="btn btn-primary btn-sm">Confirmer</button>
            </div>
        </div>
    </div>

    <!-- Modale d'alerte moderne -->
    <div id="mpp-alert" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:430px; width:calc(100vw - 28px);">
            <div class="modal-header"><h3 id="mpp-alert-title" style="color:var(--accent);">Information</h3></div>
            <div class="modal-body"><p id="mpp-alert-msg" style="margin:0;">Message.</p></div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" id="mpp-alert-ok" class="btn btn-primary btn-sm">OK</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var cModal = document.getElementById('mpp-confirm');
        var cTitle = document.getElementById('mpp-confirm-title');
        var cMsg = document.getElementById('mpp-confirm-msg');
        var cOk = document.getElementById('mpp-confirm-ok');
        var cCancel = document.getElementById('mpp-confirm-cancel');
        var cExtra = document.getElementById('mpp-confirm-extra');
        var aModal = document.getElementById('mpp-alert');
        var aTitle = document.getElementById('mpp-alert-title');
        var aMsg = document.getElementById('mpp-alert-msg');
        var aOk = document.getElementById('mpp-alert-ok');
        var pendingOk = null;
        var pendingExtra = null;
        var pendingAlertClose = null;

        function openModal(m) { m.style.display = 'flex'; setTimeout(function () { m.classList.add('active'); }, 10); }
        function closeModal(m) { m.classList.remove('active'); setTimeout(function () { m.style.display = 'none'; }, 280); }

        window.modernConfirm = function (message, onOk, opts) {
            opts = opts || {};
            cTitle.textContent = opts.titre || 'Confirmer';
            cTitle.style.color = opts.danger ? 'var(--danger)' : 'var(--accent)';
            cMsg.innerHTML = message;
            cOk.textContent = opts.bouton || 'Confirmer';
            cOk.className = opts.danger ? 'btn btn-danger btn-sm' : 'btn btn-primary btn-sm';
            pendingOk = onOk || null;
            // Bouton supplémentaire optionnel (ex. « Annuler + WhatsApp »)
            if (opts.extra && opts.extra.label) {
                cExtra.textContent = opts.extra.label;
                cExtra.className = opts.extra.className || 'btn btn-sm';
                if (opts.extra.style) { cExtra.setAttribute('style', opts.extra.style); }
                else { cExtra.removeAttribute('style'); }
                pendingExtra = opts.extra.onOk || null;
                cExtra.style.display = '';
            } else {
                pendingExtra = null;
                cExtra.style.display = 'none';
            }
            openModal(cModal);
        };
        window.modernDanger = function (message, onOk, btnLabel) {
            window.modernConfirm(message, onOk, { danger: true, bouton: btnLabel || 'Confirmer', titre: '⚠️ Confirmation' });
        };
        window.modernAlert = function (message, titre, onClose, opts) {
            opts = opts || {};
            aTitle.textContent = titre || 'Information';
            if (opts.success) {
                aTitle.style.color = '#7bd8a6';
                aOk.className = 'btn btn-success btn-sm';
            } else if (opts.danger) {
                aTitle.style.color = 'var(--danger)';
                aOk.className = 'btn btn-danger btn-sm';
            } else {
                aTitle.style.color = 'var(--accent)';
                aOk.className = 'btn btn-primary btn-sm';
            }
            aMsg.innerHTML = message;
            pendingAlertClose = onClose || null;
            openModal(aModal);
        };
        cOk.addEventListener('click', function () {
            closeModal(cModal);
            var fn = pendingOk; pendingOk = null;
            if (fn) { fn(); }
        });
        cCancel.addEventListener('click', function () { pendingOk = null; pendingExtra = null; closeModal(cModal); });
        cExtra.addEventListener('click', function () {
            closeModal(cModal);
            var fn = pendingExtra; pendingExtra = null; pendingOk = null;
            if (fn) { fn(); }
        });
        aOk.addEventListener('click', function () {
            closeModal(aModal);
            var fn = pendingAlertClose; pendingAlertClose = null;
            if (fn) { fn(); }
        });
        if (cModal) cModal.addEventListener('click', function (e) { if (e.target === this) { pendingOk = null; closeModal(cModal); } });
        if (aModal) aModal.addEventListener('click', function (e) { if (e.target === this) { var fn = pendingAlertClose; pendingAlertClose = null; closeModal(aModal); if (fn) fn(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeModal(cModal); closeModal(aModal); pendingOk = null; pendingAlertClose = null; }
        });

        // Interception automatique des formulaires data-confirm
        document.querySelectorAll('form[data-confirm]').forEach(function (f) {
            f.addEventListener('submit', function (ev) {
                var msg = f.getAttribute('data-confirm');
                var danger = f.getAttribute('data-confirm-danger') === '1';
                ev.preventDefault();
                var form = f;
                if (danger) {
                    window.modernDanger(msg, function () { form.submit(); });
                } else {
                    window.modernConfirm(msg, function () { form.submit(); });
                }
            });
        });
    })();
    </script>
    <?php
}
