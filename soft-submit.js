/**
 * Touba Lyon 2026 - Actions sans rechargement de la page.
 *
 * Tout formulaire POST de l'application est envoyé en arrière-plan (fetch) :
 *  - une notification (toast) confirme le résultat — le message de la page serveur est repris ;
 *  - la page n'est pas rechargée : le contenu est mis à jour « sur place » (les groupes ouverts,
 *    la position de défilement, les menus et les saisies en cours des autres formulaires sont conservés) ;
 *  - les confirmations modernes (data-confirm) et les `form.submit()` de la page passent aussi par là.
 * Sécurité : au moindre doute (page différente, contenu introuvable, erreur), repli sur un rechargement normal.
 *
 * Exclusions : data-ajax="off" sur le formulaire, pages de connexion / inscription / mot de passe,
 * formulaires qui produisent un téléchargement (export, pdf…), cibles externes. Coupe-circuit : window.SOFT_SUBMIT_OFF = true.
 */
(function () {
    'use strict';
    if (window.softSubmit || window.SOFT_SUBMIT_OFF) { return; }

    var EXCL = ['login.php', 'logout.php', 'register.php', 'inscription_rapide.php', 'adhesion.php', 'forgot_password.php',
                'reset_password.php', 'admin_login.php', 'admin_forgot_password.php', 'admin_reset_password.php',
                'admin_password.php', 'basculer_admin.php', 'play.php', 'kikanla.php'];
    var nativeSubmit = HTMLFormElement.prototype.submit;

    function fichier(u) {
        try { return (new URL(u, location.href).pathname.split('/').pop() || 'index.php'); } catch (e) { return ''; }
    }

    function exclu(form) {
        try {
            if (!form || form.tagName !== 'FORM') { return true; }
            if (form.getAttribute('data-ajax') === 'off') { return true; }
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return true; }
            var tg = form.getAttribute('target');
            if (tg && tg !== '_self') { return true; }
            var a = new URL(form.getAttribute('action') || location.href, location.href);
            if (a.origin !== location.origin) { return true; }
            var dest = a.pathname.split('/').pop(), cur = location.pathname.split('/').pop();
            if (EXCL.indexOf(cur) >= 0 || EXCL.indexOf(dest) >= 0) { return true; }
            if (/(export|pdf|download|csv|xls|telecharg|print)/i.test(dest)) { return true; }
            return false;
        } catch (e) { return true; }
    }

    /* ------------------------------------------------------------------ toast */
    var toastEl = null, toastTimer = null;
    function toast(type, texte) {
        if (!texte) { return; }
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.setAttribute('role', 'status');
            toastEl.setAttribute('aria-live', 'polite');
            toastEl.style.cssText = 'position:fixed; left:50%; bottom:1.2rem; transform:translateX(-50%) translateY(24px); max-width:min(92vw,560px); '
                + 'opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:10070; padding:0.75rem 1.2rem; border-radius:16px; '
                + 'font-weight:700; font-size:0.92rem; line-height:1.4; color:#fff; box-shadow:0 10px 30px rgba(0,0,0,0.5); cursor:pointer; text-align:center;';
            toastEl.addEventListener('click', function () { toastEl.style.opacity = '0'; toastEl.style.pointerEvents = 'none'; });
            document.body.appendChild(toastEl);
        }
        var ok = type === 'success', warn = type === 'warning';
        toastEl.style.background = ok ? '#14532d' : (warn ? '#713f12' : '#7f1d1d');
        toastEl.style.border = '1px solid ' + (ok ? '#25d366' : (warn ? '#f5b301' : '#e04b4b'));
        toastEl.textContent = (ok ? '✅ ' : (warn ? 'ℹ️ ' : '❌ ')) + texte;
        toastEl.style.opacity = '1';
        toastEl.style.pointerEvents = 'auto';
        toastEl.style.transform = 'translateX(-50%) translateY(0)';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toastEl.style.opacity = '0'; toastEl.style.pointerEvents = 'none'; toastEl.style.transform = 'translateX(-50%) translateY(24px)';
        }, ok ? 3200 : 5200);
    }

    /* ----------------------------------------------- message de la page serveur */
    function txt(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }

    function extraireMessage(doc) {
        var m = doc.querySelector('#notification-modal');
        if (m) {
            var t = txt(m.querySelector('.modal-body')), h = txt(m.querySelector('.modal-header'));
            if (t) { return { type: /erreur|échec|echec|refus|impossible/i.test(h) ? 'error' : 'success', text: t }; }
        }
        var d = doc.querySelector('.alert-danger, .alert-error');
        if (d && txt(d)) { return { type: 'error', text: txt(d) }; }
        var s = doc.querySelector('.alert-success');
        if (s && txt(s)) { return { type: 'success', text: txt(s) }; }
        // modernAlert(msg, titre, …) déclenché par un script de la page
        var scripts = doc.querySelectorAll('script:not([src])');
        for (var i = 0; i < scripts.length; i++) {
            var code = scripts[i].textContent || '';
            var mm = /var\s+msg\s*=\s*("(?:[^"\\]|\\.)*")/.exec(code);
            if (mm && /modernAlert\(/.test(code)) {
                try {
                    var msg = JSON.parse(mm[1]);
                    var danger = /opts\.danger\s*=\s*true/.test(code);
                    return { type: danger ? 'error' : 'success', text: String(msg) };
                } catch (e) { /* ignoré */ }
            }
        }
        return null;
    }

    function retirerMessages(doc) {
        var l = doc.querySelectorAll('#notification-modal, .alert-success, .alert-danger, .alert-error');
        for (var i = 0; i < l.length; i++) { if (l[i].parentNode) { l[i].parentNode.removeChild(l[i]); } }
    }

    /* ------------------------------------------- mise à jour du contenu (morph) */
    var CLASSES_ETAT = /^(active|open|show|shown|is-[\w-]+|mdp[\w-]*|msel[\w-]*|mdate[\w-]*|mfile[\w-]*|dp-futurs|mpp-[\w-]*)$/;

    function estAugmente(el) {
        return el.nodeType === 1 && el.classList && (el.classList.contains('msel') || el.classList.contains('mdate') || el.classList.contains('mfile'));
    }
    function aConserver(el) {
        if (el.nodeType !== 1) { return false; }
        var n = el.nodeName;
        if (n === 'SCRIPT' || n === 'STYLE' || n === 'IFRAME' || n === 'CANVAS' || n === 'NOSCRIPT') { return true; }
        if (estAugmente(el)) { return true; }
        if (el.isContentEditable || el.hasAttribute('contenteditable') || el.hasAttribute('data-soft-keep')) { return true; }
        return false;
    }
    function cle(n) {
        if (n.nodeType !== 1) { return null; }
        if (n.id) { return '#' + n.id; }
        if (n.nodeName === 'DETAILS') {
            var s = n.querySelector(':scope > summary');
            var t = (s ? s.textContent : '').replace(/[\d()]+/g, '').replace(/\s+/g, ' ').trim().slice(0, 40);
            return t ? 'D:' + t : null;
        }
        return null;
    }
    function memeGenre(a, b) {
        if (a.nodeType !== b.nodeType) { return false; }
        if (a.nodeType !== 1) { return true; }
        if (a.nodeName === b.nodeName) { return true; }
        // champ enveloppé par un composant (liste moderne, calendrier, fichier)
        return estAugmente(a) && (b.nodeName === 'SELECT' || b.nodeName === 'INPUT');
    }
    function sansDisplay(s) { return (s || '').replace(/display\s*:\s*[^;]+;?/gi, '').replace(/\s+/g, ' ').trim(); }
    function displayDe(s) { var m = /display\s*:\s*([^;]+)/i.exec(s || ''); return m ? m[1].trim() : ''; }

    function syncAttributs(f, t, dansFormulaire) {
        // retire les attributs absents du serveur
        Array.prototype.slice.call(f.attributes).forEach(function (a) {
            var n = a.name;
            if (n === 'open' || n === 'class' || n === 'style' || n === 'id' || n.indexOf('data-m') === 0 || n === 'value' || n === 'checked' || n === 'selected') { return; }
            if (!t.hasAttribute(n)) { f.removeAttribute(n); }
        });
        Array.prototype.slice.call(t.attributes).forEach(function (a) {
            var n = a.name;
            if (n === 'open' || n === 'class' || n === 'style' || n === 'value' || n === 'checked' || n === 'selected') { return; }
            if (f.getAttribute(n) !== a.value) { f.setAttribute(n, a.value); }
        });
        // classes : celles du serveur + les classes d'état posées par les scripts de la page
        var vivantes = Array.prototype.slice.call(f.classList).filter(function (c) { return CLASSES_ETAT.test(c); });
        var nouvelles = (t.getAttribute('class') || '').split(/\s+/).filter(Boolean);
        var tous = nouvelles.concat(vivantes.filter(function (c) { return nouvelles.indexOf(c) < 0; }));
        var cl = tous.join(' ');
        if ((f.getAttribute('class') || '') !== cl) { if (cl) { f.setAttribute('class', cl); } else { f.removeAttribute('class'); } }
        // style : celui du serveur, sauf « display » (basculé par les scripts)
        var sf = f.getAttribute('style') || '', st = t.getAttribute('style') || '';
        if (sansDisplay(sf) !== sansDisplay(st)) {
            var disp = displayDe(sf);
            var ns = sansDisplay(st) + (disp ? ' display:' + disp + ';' : '');
            if (ns) { f.setAttribute('style', ns); } else { f.removeAttribute('style'); }
        }
        // champs de formulaire
        var tag = f.nodeName;
        if (tag === 'INPUT' && f.type !== 'file' && f.type !== 'password') {
            if (f.type === 'checkbox' || f.type === 'radio') {
                if (dansFormulaire || f.hasAttribute('checked') !== t.hasAttribute('checked')) { f.checked = t.hasAttribute('checked'); }
                if (t.hasAttribute('checked')) { f.setAttribute('checked', ''); } else { f.removeAttribute('checked'); }
            } else {
                var tv = t.getAttribute('value') || '';
                if (dansFormulaire || (f.getAttribute('value') || '') !== tv) { f.value = tv; }
                f.setAttribute('value', tv);
            }
        }
    }

    function morphNoeud(f, t, formulaire) {
        if (f.nodeType !== 1) { if (f.nodeValue !== t.nodeValue) { f.nodeValue = t.nodeValue; } return; }
        if (aConserver(f)) { return; }
        var dansForm = !!(formulaire && (f === formulaire || formulaire.contains(f)));
        syncAttributs(f, t, dansForm);
        var tag = f.nodeName;
        if (tag === 'TEXTAREA') {
            if (dansForm || (f.defaultValue !== t.textContent)) { f.value = t.textContent; }
            return;
        }
        if (tag === 'SELECT') {
            var vivante = f.value;
            morphEnfants(f, t, formulaire);
            var existe = Array.prototype.some.call(f.options, function (o) { return o.value === vivante; });
            if (!dansForm && existe) { f.value = vivante; }
            return;
        }
        if (tag === 'OPTION') { f.selected = t.hasAttribute('selected'); return; }
        // zone remplie par un script (vide côté serveur) : on la laisse telle quelle
        if (t.childNodes.length === 0 && f.childNodes.length > 0 && tag !== 'DETAILS') { return; }
        morphEnfants(f, t, formulaire);
    }

    function morphEnfants(f, t, formulaire) {
        var fk = Array.prototype.slice.call(f.childNodes), tk = Array.prototype.slice.call(t.childNodes);
        var pool = {}, sansCle = [];
        fk.forEach(function (n) { var k = cle(n); if (k) { (pool[k] = pool[k] || []).push(n); } else { sansCle.push(n); } });
        var ri = 0, ordre = [];
        tk.forEach(function (tn) {
            var k = cle(tn), m = null;
            if (k) {
                if (pool[k] && pool[k].length) { m = pool[k].shift(); }
            } else {
                while (ri < sansCle.length) {
                    var c = sansCle[ri++];
                    if (memeGenre(c, tn)) { m = c; break; }
                }
            }
            if (m) { morphNoeud(m, tn, formulaire); ordre.push(m); }
            else { ordre.push(document.importNode(tn, true)); }
        });
        // les noeuds du serveur disparus sont retirés (sauf composants / contenus gérés par la page)
        var gardes = [];
        fk.forEach(function (n) { if (ordre.indexOf(n) < 0 && aConserver(n) && n.nodeType === 1) { gardes.push(n); } });
        fk.forEach(function (n) { if (ordre.indexOf(n) < 0 && gardes.indexOf(n) < 0 && n.parentNode === f) { f.removeChild(n); } });
        // ordre final
        var ref = null;
        for (var i = ordre.length - 1; i >= 0; i--) {
            var n = ordre[i];
            if (n.parentNode !== f || n.nextSibling !== ref) { f.insertBefore(n, ref); }
            ref = n;
        }
    }

    function choisirConteneur(doc) {
        var sels = ['.admin-content', '.dashboard-main', 'main.container', 'main', '.container'];
        for (var i = 0; i < sels.length; i++) {
            var live = document.querySelector(sels[i]), next = doc.querySelector(sels[i]);
            if (live && next) { return { live: live, next: next }; }
        }
        return null;
    }

    /* --------------------------------------------------------------- envoi */
    function telecharger(blob, cd) {
        var nom = 'fichier';
        var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd || '');
        if (m) { try { nom = decodeURIComponent(m[1]); } catch (e) { nom = m[1]; } }
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = nom;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    function traiter(res, html, form) {
        if (res.status >= 500) { toast('error', "Erreur du serveur : l'action n'a peut-être pas abouti."); return; }
        if (fichier(res.url) !== fichier(location.href)) { window.location.href = res.url; return; } // autre page : navigation normale
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var msg = extraireMessage(doc);
        retirerMessages(doc);
        var c = choisirConteneur(doc);
        if (!c) { window.location.reload(); return; }
        try { morphEnfants(c.live, c.next, form); }
        catch (e) { if (window.console) { console.error('soft-submit', e); } window.location.reload(); return; }
        try {
            var u = new URL(res.url);
            if (u.search !== location.search) { history.replaceState(null, '', u.pathname + u.search + location.hash); }
        } catch (e) { /* ignoré */ }
        document.dispatchEvent(new CustomEvent('soft:updated'));
        if (msg) { toast(msg.type, msg.text); } else { toast('success', 'Action effectuée'); }
    }

    function softSubmit(form, submitter, options) {
        options = options || {};
        if (!options.force && exclu(form)) { return nativeSubmit.call(form); }
        if (form.__soft) { return; }
        var fd;
        try { fd = new FormData(form); } catch (e) { return nativeSubmit.call(form); }
        if (submitter && submitter.name && !fd.has(submitter.name)) { fd.append(submitter.name, submitter.value); }
        form.__soft = true;
        var boutons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'));
        var avant = boutons.map(function (b) { return b.disabled; });
        boutons.forEach(function (b) { b.disabled = true; b.style.opacity = '0.6'; });
        fetch(form.getAttribute('action') || location.href, {
            method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            var ct = (res.headers.get('Content-Type') || '').toLowerCase(), cd = res.headers.get('Content-Disposition') || '';
            if (/attachment/i.test(cd) || (ct && ct.indexOf('text/html') < 0 && ct.indexOf('json') < 0)) {
                return res.blob().then(function (b) { telecharger(b, cd); toast('success', 'Fichier téléchargé'); });
            }
            if (ct.indexOf('json') >= 0) {
                return res.json().then(function (d) { toast(d && d.ok === false ? 'error' : 'success', (d && (d.message || d.msg)) || 'Action effectuée'); });
            }
            return res.text().then(function (t) { traiter(res, t, form); });
        }).catch(function () {
            toast('error', 'Connexion impossible : réessayez.');
        }).then(function () {
            form.__soft = false;
            boutons.forEach(function (b, i) { b.disabled = avant[i]; b.style.opacity = ''; });
        });
    }

    window.softSubmit = softSubmit;
    window.__soft = { morphEnfants: morphEnfants, extraireMessage: extraireMessage, toast: toast };

    // form.submit() appelé par la page (liste qui s'envoie au changement, confirmation moderne…)
    HTMLFormElement.prototype.submit = function () { softSubmit(this); };

    // Envois classiques (bouton) : après les contrôles de la page (confirmation, validation)
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) { return; }
        var f = e.target;
        if (exclu(f)) { return; }
        e.preventDefault();
        softSubmit(f, e.submitter || null);
    });
})();
