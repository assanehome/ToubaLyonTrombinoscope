/**
 * Touba Lyon 2026 - Message « adresse e-mail déjà utilisée » sur les formulaires d'inscription.
 *
 * - Vérifie l'adresse en direct (email_disponible.php) quand on quitte le champ.
 * - Affiche aussi, sous le champ, l'erreur renvoyée par le serveur (window.TROBA_EMAIL_ERREUR).
 * - Empêche l'envoi tant que l'adresse saisie est signalée comme déjà utilisée.
 */
(function () {
    var input = document.getElementById('email');
    if (!input) return;

    var box = document.createElement('div');
    box.id = 'email-msg';
    box.setAttribute('role', 'alert');
    box.style.cssText = 'display:none; margin-top:0.5rem; padding:0.65rem 0.85rem; border-radius:10px; font-size:0.88rem; line-height:1.45; background:rgba(191,33,33,0.12); border:1px solid rgba(191,33,33,0.45); color:#fca5a5;';
    input.parentNode.insertBefore(box, input.nextSibling);

    var dernier = { valeur: '', utilise: false };
    var seq = 0, minuteur = null;

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function afficher(message, statut) {
        var liens = '';
        if (statut === 'approved') {
            liens = '<div style="margin-top:0.35rem;"><a href="login.php" style="color:#fff; font-weight:700;">Se connecter</a> · <a href="forgot_password.php" style="color:#fff; font-weight:700;">Mot de passe oublié ?</a></div>';
        } else if (statut === 'pending') {
            liens = '<div style="margin-top:0.35rem;"><a href="login.php" style="color:#fff; font-weight:700;">Se connecter pour suivre ma demande</a></div>';
        }
        box.innerHTML = '⚠️ ' + esc(message) + liens;
        box.style.display = 'block';
        input.style.borderColor = 'rgba(191,33,33,0.8)';
    }
    function masquer() {
        box.style.display = 'none';
        box.innerHTML = '';
        input.style.borderColor = '';
    }

    function verifier() {
        var v = input.value.trim();
        if (v === dernier.valeur) { return; }
        if (v === '' || v.indexOf('@') < 1 || !input.validity.valid) { dernier = { valeur: v, utilise: false }; masquer(); return; }
        var n = ++seq;
        fetch('email_disponible.php?email=' + encodeURIComponent(v), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (n !== seq) { return; } // réponse périmée
                dernier = { valeur: v, utilise: !!d.used };
                if (d.used) { afficher(d.message, d.status); } else { masquer(); }
            })
            .catch(function () { /* hors ligne : le contrôle serveur reste actif */ });
    }

    input.addEventListener('blur', verifier);
    input.addEventListener('input', function () {
        if (input.value.trim() !== dernier.valeur) { dernier.utilise = false; masquer(); }
        clearTimeout(minuteur);
        minuteur = setTimeout(verifier, 600);
    });

    if (input.form) {
        input.form.addEventListener('submit', function (e) {
            if (dernier.utilise && input.value.trim() === dernier.valeur) {
                e.preventDefault();
                e.stopImmediatePropagation();
                box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                input.focus();
            }
        }, true);
    }

    // Erreur renvoyée par le serveur à propos de l'adresse : affichée sous le champ
    if (window.TROBA_EMAIL_ERREUR) {
        afficher(window.TROBA_EMAIL_ERREUR, /en attente/.test(window.TROBA_EMAIL_ERREUR) ? 'pending' : (/déjà inscrite|déjà utilisée/.test(window.TROBA_EMAIL_ERREUR) ? 'approved' : ''));
        dernier = { valeur: input.value.trim(), utilise: true };
        box.scrollIntoView({ block: 'center' });
    }
})();
