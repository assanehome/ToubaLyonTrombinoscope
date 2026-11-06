/**
 * Touba Lyon 2026 - Validation de l'adresse e-mail par code (inscription).
 *
 * Sous le champ e-mail : « Recevoir le code » → saisie des 6 chiffres → « ✓ Adresse vérifiée ».
 * L'envoi du formulaire est bloqué tant que l'adresse n'est pas vérifiée (le serveur contrôle aussi).
 * Modifier l'adresse annule la vérification.
 */
(function () {
    var input = document.getElementById('email');
    if (!input || !input.form) return;
    var form = input.form;

    var box = document.createElement('div');
    box.id = 'email-verif';
    box.style.cssText = 'margin-top:0.5rem;';
    // placé après l'éventuel message « adresse déjà utilisée »
    var ref = document.getElementById('email-msg') || input;
    ref.parentNode.insertBefore(box, ref.nextSibling);

    var verifie = false, verifieEmail = '', cooldown = 0, timer = null, envoye = false;

    function post(data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch('email_code.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function valeur() { return input.value.trim().toLowerCase(); }
    function valide() { var v = input.value.trim(); return v.indexOf('@') > 0 && input.validity.valid; }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    var STYLE_BTN = 'cursor:pointer; border-radius:10px; padding:0.6rem 0.9rem; font-weight:700; font-size:0.9rem; border:1px solid rgba(212,175,55,0.6); background:rgba(212,175,55,0.15); color:var(--gold, #d4af37);';
    var message = { texte: '', type: '' };

    function msgHtml() {
        if (!message.texte) return '';
        var c = message.type === 'ok' ? 'color:#7bd8a6;' : (message.type === 'err' ? 'color:#fca5a5;' : 'color:var(--text-muted);');
        return '<div style="margin-top:0.45rem; font-size:0.85rem; line-height:1.4; ' + c + '">' + esc(message.texte) + '</div>';
    }

    function rendre() {
        if (verifie && verifieEmail === valeur()) {
            box.innerHTML = '<div style="padding:0.6rem 0.85rem; border-radius:10px; background:rgba(37,211,102,0.12); border:1px solid rgba(37,211,102,0.45); color:#7bd8a6; font-weight:700; font-size:0.9rem;">✓ Adresse e-mail vérifiée</div>';
            return;
        }
        if (!valide()) { box.innerHTML = '<div style="color:var(--text-muted); font-size:0.82rem;">📧 Un code de vérification vous sera envoyé par e-mail.</div>'; return; }
        var html = '<div style="padding:0.75rem 0.85rem; border-radius:12px; background:rgba(212,175,55,0.07); border:1px solid rgba(212,175,55,0.35);">';
        if (!envoye) {
            html += '<div style="color:var(--white); font-size:0.88rem; margin-bottom:0.5rem;">📧 Validez votre adresse e-mail pour pouvoir vous inscrire.</div>'
                + '<button type="button" id="ev-send" style="' + STYLE_BTN + '">Recevoir le code par e-mail</button>';
        } else {
            html += '<div style="color:var(--white); font-size:0.88rem; margin-bottom:0.5rem;">Saisissez le code à 6 chiffres reçu par e-mail :</div>'
                + '<div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">'
                + '<input type="text" id="ev-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" class="form-input" style="width:9.5rem; text-align:center; letter-spacing:0.35rem; font-size:1.2rem; font-weight:700;">'
                + '<button type="button" id="ev-check" style="' + STYLE_BTN + '">Valider</button>'
                + '<button type="button" id="ev-resend" style="' + STYLE_BTN.replace('rgba(212,175,55,0.15)', 'transparent') + '"' + (cooldown > 0 ? ' disabled' : '') + '>'
                + (cooldown > 0 ? 'Renvoyer (' + cooldown + ' s)' : 'Renvoyer le code') + '</button></div>'
                + '<div style="margin-top:0.6rem; font-size:0.82rem; color:var(--text-muted); line-height:1.45;">Code non reçu ? Vérifiez vos courriers indésirables, renvoyez le code, ou '
                + '<a href="#" id="ev-change" style="color:var(--gold, #d4af37); font-weight:700;">✏️ modifiez votre adresse e-mail</a> (faute de frappe ?).</div>';
        }
        html += msgHtml() + '</div>';
        box.innerHTML = html;
        var s = document.getElementById('ev-send'); if (s) s.addEventListener('click', envoyer);
        var c = document.getElementById('ev-check'); if (c) c.addEventListener('click', controler);
        var r = document.getElementById('ev-resend'); if (r) r.addEventListener('click', envoyer);
        var ch = document.getElementById('ev-change');
        if (ch) { ch.addEventListener('click', function (e) { e.preventDefault(); changerAdresse(); }); }
        var k = document.getElementById('ev-code');
        if (k) {
            k.addEventListener('input', function () { k.value = k.value.replace(/\D+/g, '').slice(0, 6); if (k.value.length === 6) { controler(); } });
            k.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); controler(); } });
        }
    }

    function demarrerCooldown(s) {
        cooldown = s;
        clearInterval(timer);
        timer = setInterval(function () {
            cooldown--;
            var r = document.getElementById('ev-resend');
            if (cooldown <= 0) { clearInterval(timer); cooldown = 0; if (r) { r.disabled = false; r.textContent = 'Renvoyer le code'; } }
            else if (r) { r.textContent = 'Renvoyer (' + cooldown + ' s)'; }
        }, 1000);
    }

    // Code non reçu : retour à la saisie de l'adresse (le champ e-mail est sélectionné pour la corriger)
    function changerAdresse() {
        envoye = false; message = { texte: '', type: '' };
        clearInterval(timer); cooldown = 0;
        rendre();
        input.focus();
        try { input.select(); } catch (e) { /* ignoré */ }
        input.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function envoyer() {
        var btn = document.getElementById('ev-send') || document.getElementById('ev-resend');
        if (btn) { btn.disabled = true; btn.textContent = 'Envoi…'; }
        post({ action: 'send', email: valeur() }).then(function (d) {
            if (d.ok) {
                envoye = true; message = { texte: d.message, type: 'info' };
                demarrerCooldown(60);
            } else {
                message = { texte: d.message || "L'envoi a échoué.", type: 'err' };
                if (d.wait) { envoye = true; demarrerCooldown(d.wait); }
            }
            rendre();
            var k = document.getElementById('ev-code'); if (k && d.ok) { k.focus(); }
        }).catch(function () { message = { texte: "Connexion impossible. Réessayez.", type: 'err' }; rendre(); });
    }

    function controler() {
        var k = document.getElementById('ev-code');
        if (!k || k.value.length !== 6) { message = { texte: 'Saisissez les 6 chiffres du code.', type: 'err' }; rendre(); return; }
        var code = k.value;
        post({ action: 'verify', email: valeur(), code: code }).then(function (d) {
            if (d.ok && d.verified) { verifie = true; verifieEmail = valeur(); message = { texte: '', type: '' }; rendre(); return; }
            message = { texte: d.message || 'Code incorrect.', type: 'err' };
            if (d.expired) { envoye = false; }
            rendre();
            var k2 = document.getElementById('ev-code'); if (k2) { k2.value = ''; k2.focus(); }
        }).catch(function () { message = { texte: "Connexion impossible. Réessayez.", type: 'err' }; rendre(); });
    }

    // Modification de l'adresse : la vérification et le code envoyé ne valent plus
    var derniere = valeur();
    input.addEventListener('input', function () {
        if (valeur() !== derniere) {
            derniere = valeur();
            verifie = false; envoye = false; message = { texte: '', type: '' };
            rendre();
        }
    });
    input.addEventListener('blur', rendre);

    // Envoi du formulaire bloqué tant que l'adresse n'est pas vérifiée
    form.addEventListener('submit', function (e) {
        if (!(verifie && verifieEmail === valeur())) {
            e.preventDefault();
            e.stopImmediatePropagation();
            message = { texte: valide() ? "Validez votre adresse e-mail avec le code reçu avant de vous inscrire." : "Saisissez une adresse e-mail valide.", type: 'err' };
            rendre();
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, true);

    // Rechargement de la page (ex. erreur sur un autre champ) : l'adresse déjà vérifiée le reste
    rendre();
    if (valide()) {
        post({ action: 'status', email: valeur() }).then(function (d) {
            if (d.ok && d.verified) { verifie = true; verifieEmail = valeur(); rendre(); }
        }).catch(function () { /* ignoré */ });
    }
})();
