<?php
/**
 * Touba Lyon 2026 - Validation de l'adresse e-mail par code, APRÈS l'envoi de l'inscription.
 *
 * Déroulé (register.php, inscription_rapide.php, adhesion.php) :
 *  1. le formulaire est envoyé : le membre est enregistré avec le statut « email_a_verifier »
 *     (invisible des administrateurs) et un code à 6 chiffres lui est envoyé par e-mail ;
 *  2. un écran de saisie du code s'ouvre (renvoi du code, modification de l'adresse possibles) ;
 *  3. code correct : le statut passe à « pending » (en attente de validation par un administrateur),
 *     l'e-mail « inscription reçue » part, et l'encart de connexion s'affiche.
 * Si la base n'a pas (encore) le statut « email_a_verifier », l'inscription fonctionne comme avant.
 */
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/dahira_emails.php';

const VERIF_STATUT = 'email_a_verifier';

/** Statut à donner à une nouvelle inscription : « email_a_verifier » si la base le permet, sinon « pending ». */
function verif_statut_initial(PDO $pdo): string
{
    static $s = null;
    if ($s !== null) { return $s; }
    $s = 'pending';
    try {
        $r = $pdo->query("SHOW COLUMNS FROM membres LIKE 'status'")->fetch();
        if ($r && stripos((string) ($r['Type'] ?? ''), VERIF_STATUT) !== false) { $s = VERIF_STATUT; }
    } catch (Exception $e) { /* repli : pending */ }
    return $s;
}

/** Supprime un membre non vérifié (photo, code et ligne). */
function verif_supprimer_membre(PDO $pdo, int $id): void
{
    try {
        $st = $pdo->prepare("SELECT photo_path FROM membres WHERE id = ? AND status = ?");
        $st->execute([$id, VERIF_STATUT]);
        $row = $st->fetch();
        if (!$row) { return; }
        $photo = (string) ($row['photo_path'] ?? '');
        if ($photo !== '' && $photo !== 'default.jpg') {
            $f = __DIR__ . '/uploads/' . basename($photo);
            if (is_file($f)) { @unlink($f); }
        }
        $pdo->prepare("DELETE FROM email_verifications WHERE membre_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM membres WHERE id = ? AND status = ?")->execute([$id, VERIF_STATUT]);
    } catch (Exception $e) {
        error_log('Touba Lyon verif suppression : ' . $e->getMessage());
    }
}

/** Libère l'adresse : supprime les inscriptions non vérifiées qui l'utilisent (nouvelle tentative) + purge les anciennes. */
function verif_liberer_email(PDO $pdo, string $email): void
{
    try {
        $st = $pdo->prepare("SELECT id FROM membres WHERE LOWER(email) = LOWER(?) AND status = ?");
        $st->execute([$email, VERIF_STATUT]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { verif_supprimer_membre($pdo, (int) $id); }
        // purge : inscriptions jamais vérifiées depuis plus de 3 jours
        $old = $pdo->query("SELECT m.id FROM membres m JOIN email_verifications v ON v.membre_id = m.id WHERE m.status = '" . VERIF_STATUT . "' AND v.created_at < (NOW() - INTERVAL 3 DAY)");
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id) { verif_supprimer_membre($pdo, (int) $id); }
    } catch (Exception $e) {
        error_log('Touba Lyon verif libération : ' . $e->getMessage());
    }
}

/** E-mail du membre dont l'adresse est en cours de vérification (session), ou ''. */
function verif_etat(PDO $pdo): string
{
    $id = (int) ($_SESSION['verif_membre_id'] ?? 0);
    if ($id <= 0) { return ''; }
    try {
        $st = $pdo->prepare("SELECT email FROM membres WHERE id = ? AND status = ?");
        $st->execute([$id, VERIF_STATUT]);
        $e = $st->fetchColumn();
        if ($e !== false) { return (string) $e; }
    } catch (Exception $ex) { /* ignoré */ }
    unset($_SESSION['verif_membre_id']);
    return '';
}

/** Abandon : supprime l'inscription non vérifiée de la session. */
function verif_annuler(PDO $pdo): void
{
    $id = (int) ($_SESSION['verif_membre_id'] ?? 0);
    if ($id > 0) { verif_supprimer_membre($pdo, $id); }
    unset($_SESSION['verif_membre_id'], $_SESSION['verif_termine']);
}

/**
 * Génère et envoie un code. $force : ignore le délai de 60 s (adresse modifiée, premier envoi).
 * @return array{ok:bool,message:string,wait?:int,email?:string}
 */
function verif_envoyer_code(PDO $pdo, int $membreId, bool $force = false): array
{
    $st = $pdo->prepare("SELECT email, prenom FROM membres WHERE id = ? AND status = ?");
    $st->execute([$membreId, VERIF_STATUT]);
    $m = $st->fetch();
    if (!$m) { return ['ok' => false, 'message' => "Inscription introuvable. Recommencez l'inscription."]; }
    $email = strtolower(trim((string) $m['email']));

    $v = $pdo->prepare("SELECT UNIX_TIMESTAMP(sent_at) AS t FROM email_verifications WHERE membre_id = ?");
    $v->execute([$membreId]);
    $dernier = (int) $v->fetchColumn();
    if (!$force && $dernier > time() - 60) {
        return ['ok' => false, 'wait' => 60 - (time() - $dernier), 'message' => "Patientez quelques secondes avant de redemander un code."];
    }
    // Limites : 3 envois / 30 min par adresse, 10 / heure par IP
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    try {
        $c1 = $pdo->prepare("SELECT COUNT(*) FROM email_verif_envois WHERE email = ? AND created_at > (NOW() - INTERVAL 30 MINUTE)");
        $c1->execute([$email]);
        $c2 = $pdo->prepare("SELECT COUNT(*) FROM email_verif_envois WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $c2->execute([$ip]);
        if ((int) $c1->fetchColumn() >= 3 || (int) $c2->fetchColumn() >= 10) {
            return ['ok' => false, 'message' => "Trop de demandes de code. Réessayez dans quelques minutes."];
        }
        $pdo->prepare("INSERT INTO email_verif_envois (email, ip) VALUES (?, ?)")->execute([$email, $ip]);
        $pdo->exec("DELETE FROM email_verif_envois WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    } catch (Exception $e) { error_log('Touba Lyon verif limite : ' . $e->getMessage()); }

    $code = (string) random_int(100000, 999999);
    $salt = bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO email_verifications (membre_id, code_hash, salt, expires_at, tries, sent_at) VALUES (?, ?, ?, (NOW() + INTERVAL 10 MINUTE), 0, NOW())
                   ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), salt = VALUES(salt), expires_at = VALUES(expires_at), tries = 0, sent_at = NOW()")
        ->execute([$membreId, hash('sha256', $salt . $code), $salt]);

    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;">'
        . '<h2 style="color:#1b4332;">As-salaamu haleykum 🙏</h2>'
        . '<p style="color:#333;">Voici votre code pour valider votre adresse e-mail et confirmer votre inscription au Dahira :</p>'
        . '<p style="text-align:center;margin:22px 0;"><span style="display:inline-block;font-size:32px;letter-spacing:8px;font-weight:bold;color:#1b4332;background:#f6f3e8;border:1px solid #d4af37;border-radius:10px;padding:12px 22px;">' . $code . '</span></p>'
        . '<p style="color:#555;font-size:14px;">Ce code est valable <strong>10 minutes</strong>. Si vous n\'êtes pas à l\'origine de cette inscription, ignorez simplement ce message.</p>'
        . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p></div>';
    $ok = false;
    try { $ok = send_smtp_mail($email, (string) $m['prenom'], 'Votre code de vérification — Dahira Touba Lyon', $html, "Votre code de vérification : $code (valable 10 minutes)."); }
    catch (Throwable $e) { error_log('Touba Lyon verif envoi : ' . $e->getMessage()); }
    if (!$ok) { return ['ok' => false, 'message' => "L'envoi du code a échoué. Vérifiez l'adresse (vous pouvez la modifier) puis redemandez un code.", 'email' => $email]; }
    return ['ok' => true, 'message' => "Code envoyé à $email. Pensez à vérifier vos courriers indésirables.", 'email' => $email];
}

/**
 * À appeler juste après l'INSERT de l'inscription. Retourne true si l'écran de code doit s'ouvrir ;
 * false si la vérification n'est pas disponible (l'inscription est alors finalisée directement).
 */
function verif_apres_insertion(PDO $pdo, int $membreId, string $origine): bool
{
    if ($membreId <= 0) { return false; }
    if (verif_statut_initial($pdo) !== VERIF_STATUT) {
        verif_finaliser($pdo, $membreId, $origine);
        return false;
    }
    try {
        $pdo->prepare("INSERT INTO email_verifications (membre_id, code_hash, salt, expires_at, origine) VALUES (?, '', '', NOW(), ?)
                       ON DUPLICATE KEY UPDATE origine = VALUES(origine)")->execute([$membreId, $origine]);
        $_SESSION['verif_membre_id'] = $membreId;
        unset($_SESSION['verif_termine']);
        verif_envoyer_code($pdo, $membreId, true);
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon verif démarrage : ' . $e->getMessage());
        // repli : l'inscription reste valable, sans vérification
        try { $pdo->prepare("UPDATE membres SET status = 'pending' WHERE id = ? AND status = ?")->execute([$membreId, VERIF_STATUT]); } catch (Exception $e2) { /* ignoré */ }
        verif_finaliser($pdo, $membreId, $origine);
        return false;
    }
}

/** Code valide : passe en attente de validation admin et envoie les e-mails d'inscription. */
function verif_finaliser(PDO $pdo, int $membreId, string $origine = ''): void
{
    try {
        $pdo->prepare("UPDATE membres SET status = 'pending' WHERE id = ? AND status = ?")->execute([$membreId, VERIF_STATUT]);
        if ($origine === '') {
            $o = $pdo->prepare("SELECT origine FROM email_verifications WHERE membre_id = ?");
            $o->execute([$membreId]);
            $origine = (string) $o->fetchColumn();
        }
        $pdo->prepare("DELETE FROM email_verifications WHERE membre_id = ?")->execute([$membreId]);
        $st = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
        $st->execute([$membreId]);
        $m = $st->fetch();
        if (!$m) { return; }
        $nom = trim($m['prenom'] . ' ' . $m['nom']);

        // Notification au secrétariat (formulaire d'adhésion complet)
        if ($origine === 'adhesion') {
            $recap = [
                "Type d'adhésion" => $m['type_adhesion'], 'Nom' => $m['nom'], 'Prénom' => $m['prenom'], 'Genre' => $m['genre'],
                'Test Kourel' => ($m['test_kourel'] ?: '—'), 'Adresse' => ($m['adresse'] ?: '—'), 'Code postal' => $m['code_postal'],
                'Commune' => $m['commune'], 'Téléphone' => $m['telephone'], 'Email' => $m['email'], 'Statut' => $m['statut'],
                "Secteur d'activité" => $m['secteur_activite'], 'Profession' => $m['profession'], 'Commentaires' => ($m['commentaires'] ?: '—'),
                "Année d'intégration" => $m['annee_integration'], 'Charte acceptée' => 'Oui',
            ];
            $rows = '';
            foreach ($recap as $label => $val) {
                $rows .= '<tr><td style="padding:6px 12px;border:1px solid #e0e0e0;font-weight:bold;background:#f7f7f7;">' . htmlspecialchars((string) $label)
                    . '</td><td style="padding:6px 12px;border:1px solid #e0e0e0;">' . nl2br(htmlspecialchars((string) $val)) . '</td></tr>';
            }
            $html = '<div style="font-family:Arial,sans-serif;color:#1a1a1a;"><h2 style="color:#1b4332;">Nouvelle inscription Dahira Touba Lyon</h2>'
                . '<p>Une nouvelle demande d\'adhésion vient d\'être soumise (adresse e-mail vérifiée) :</p>'
                . '<table style="border-collapse:collapse;font-size:14px;">' . $rows . '</table>'
                . '<p style="font-size:0.85em;color:#888;margin-top:16px;">Touba Lyon — notification automatique.</p></div>';
            @send_smtp_mail(SMTP_TO_EMAIL, 'Secrétariat Touba Lyon', 'Nouvelle inscription Dahira : ' . $nom, $html);
        }
        // Confirmation à l'inscrit : demande reçue, en attente de validation par un administrateur
        @send_inscription_email($m['email'], $nom);
    } catch (Exception $e) {
        error_log('Touba Lyon verif finalisation : ' . $e->getMessage());
    }
}

/** Écran de saisie du code (remplace le formulaire d'inscription). $page : page à rouvrir après validation. */
function verif_ecran_html(string $email, string $page): string
{
    $e = htmlspecialchars($email, ENT_QUOTES);
    $p = htmlspecialchars($page, ENT_QUOTES);
    return '
<div class="glass-card" id="vf-box" style="border:2px solid rgba(212,175,55,0.5); border-radius:12px; padding:1.6rem 1.5rem; margin-bottom:1.5rem; text-align:left;">
    <div style="display:flex; align-items:center; gap:0.8rem; margin-bottom:0.9rem;">
        <div style="width:46px; height:46px; border-radius:50%; background:rgba(212,175,55,0.16); display:flex; align-items:center; justify-content:center; font-size:1.5rem;">📧</div>
        <div>
            <div style="color:var(--gold); font-weight:800; font-size:1.15rem; line-height:1.2;">Validez votre adresse e-mail</div>
            <div style="color:var(--text-muted); font-size:0.85rem;">Dernière étape pour confirmer votre inscription</div>
        </div>
    </div>
    <p style="margin:0 0 1rem; color:var(--white); line-height:1.55;">Nous avons envoyé un code à 6 chiffres à <strong id="vf-email">' . $e . '</strong>. Saisissez-le ci-dessous.</p>
    <div style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center;">
        <input type="text" id="vf-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" class="form-input" style="width:11rem; text-align:center; letter-spacing:0.4rem; font-size:1.4rem; font-weight:800;">
        <button type="button" id="vf-check" class="btn btn-primary">Valider le code</button>
    </div>
    <div id="vf-msg" style="margin-top:0.7rem; font-size:0.88rem; line-height:1.4; display:none;"></div>
    <div style="margin-top:1rem; padding-top:0.9rem; border-top:1px solid rgba(255,255,255,0.1); font-size:0.85rem; color:var(--text-muted); line-height:1.6;">
        Code non reçu ? Vérifiez vos courriers indésirables, puis
        <button type="button" id="vf-resend" class="btn btn-secondary btn-sm">Renvoyer le code</button>
        ou <a href="#" id="vf-change-link" style="color:var(--gold); font-weight:700;">✏️ modifier mon adresse e-mail</a>.
    </div>
    <div id="vf-change" style="display:none; margin-top:0.9rem; padding:0.9rem; border-radius:10px; background:rgba(212,175,55,0.07); border:1px solid rgba(212,175,55,0.35);">
        <label for="vf-newemail" class="form-label">Nouvelle adresse e-mail</label>
        <input type="email" id="vf-newemail" class="form-input" value="' . $e . '" autocomplete="email">
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem;">
            <button type="button" id="vf-change-ok" class="btn btn-primary btn-sm">Envoyer le code à cette adresse</button>
            <button type="button" id="vf-change-cancel" class="btn btn-secondary btn-sm">Annuler</button>
        </div>
    </div>
    <p style="margin:1rem 0 0; font-size:0.8rem;"><a href="' . $p . '?recommencer=1" style="color:var(--text-muted);" data-confirm="Abandonner cette inscription et recommencer ?">← Abandonner et recommencer l\'inscription</a></p>
</div>
<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var cooldown = 0, timer = null;
    function post(data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch("email_code.php", { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); });
    }
    function msg(t, type) {
        var m = $("vf-msg");
        if (!t) { m.style.display = "none"; return; }
        m.style.display = "block";
        m.style.color = type === "ok" ? "#7bd8a6" : (type === "err" ? "#fca5a5" : "var(--text-muted)");
        m.textContent = t;
    }
    function demarrer(s) {
        cooldown = s; clearInterval(timer);
        var b = $("vf-resend");
        function maj() { b.disabled = cooldown > 0; b.textContent = cooldown > 0 ? "Renvoyer (" + cooldown + " s)" : "Renvoyer le code"; }
        maj();
        timer = setInterval(function () { cooldown--; maj(); if (cooldown <= 0) { clearInterval(timer); } }, 1000);
    }
    function apres(d) {
        if (d.email) { $("vf-email").textContent = d.email; $("vf-newemail").value = d.email; }
        msg(d.message, d.ok ? "info" : "err");
        if (d.ok) { demarrer(60); $("vf-code").value = ""; $("vf-code").focus(); }
        else if (d.wait) { demarrer(d.wait); }
    }
    function verifier() {
        var c = $("vf-code").value.replace(/\D+/g, "");
        if (c.length !== 6) { msg("Saisissez les 6 chiffres du code.", "err"); return; }
        $("vf-check").disabled = true;
        post({ action: "verify", code: c }).then(function (d) {
            $("vf-check").disabled = false;
            if (d.ok && d.verified) { msg("Adresse validée ✓", "ok"); window.location.href = "' . $p . '"; return; }
            msg(d.message || "Code incorrect.", "err");
            $("vf-code").value = ""; $("vf-code").focus();
        }).catch(function () { $("vf-check").disabled = false; msg("Connexion impossible. Réessayez.", "err"); });
    }
    $("vf-code").addEventListener("input", function () { this.value = this.value.replace(/\D+/g, "").slice(0, 6); if (this.value.length === 6) { verifier(); } });
    $("vf-code").addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); verifier(); } });
    $("vf-check").addEventListener("click", verifier);
    $("vf-resend").addEventListener("click", function () { post({ action: "send" }).then(apres).catch(function () { msg("Connexion impossible. Réessayez.", "err"); }); });
    $("vf-change-link").addEventListener("click", function (e) { e.preventDefault(); $("vf-change").style.display = "block"; $("vf-newemail").focus(); $("vf-newemail").select(); });
    $("vf-change-cancel").addEventListener("click", function () { $("vf-change").style.display = "none"; });
    $("vf-change-ok").addEventListener("click", function () {
        var n = $("vf-newemail").value.trim();
        if (!n) { msg("Saisissez une adresse e-mail.", "err"); return; }
        $("vf-change-ok").disabled = true;
        post({ action: "change", email: n }).then(function (d) {
            $("vf-change-ok").disabled = false;
            if (d.ok) { $("vf-change").style.display = "none"; }
            apres(d);
        }).catch(function () { $("vf-change-ok").disabled = false; msg("Connexion impossible. Réessayez.", "err"); });
    });
    demarrer(60);
})();
</script>';
}
