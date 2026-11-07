<?php
/**
 * Touba Lyon 2026 - Après l'inscription : message + formulaire de connexion membre.
 *
 * Affiché à la place du formulaire d'inscription (register.php, inscription_rapide.php,
 * adhesion.php). Le nouveau membre peut se connecter tout de suite : tant que son compte
 * est en attente, il accède à son profil pour suivre la validation et modifier ses informations.
 */

/** Encart de réussite avec formulaire de connexion (l'adresse e-mail est préremplie). */
function inscription_login_card_html(string $email, string $message = ''): string
{
    $e = htmlspecialchars($email, ENT_QUOTES);
    $msg = $message !== '' ? '<p style="margin:0 0 1rem; color:var(--white); line-height:1.55;">' . htmlspecialchars($message) . '</p>' : '';
    return '
<div class="glass-card" style="border:2px solid rgba(37,211,102,0.45); border-radius:12px; padding:1.6rem 1.5rem; margin-bottom:1.5rem; text-align:left;">
    <div style="display:flex; align-items:center; gap:0.8rem; margin-bottom:0.9rem;">
        <div style="width:46px; height:46px; border-radius:50%; background:rgba(37,211,102,0.18); display:flex; align-items:center; justify-content:center; font-size:1.5rem;">✅</div>
        <div>
            <div style="color:#7bd8a6; font-weight:800; font-size:1.15rem; line-height:1.2;">Inscription envoyée</div>
            <div style="color:var(--text-muted); font-size:0.85rem;">En attente de validation par un administrateur</div>
        </div>
    </div>
    ' . $msg . '
    <div style="background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.35); border-radius:10px; padding:1rem 1.1rem;">
        <div style="color:var(--gold); font-weight:800; margin-bottom:0.3rem;">🔑 Suivez votre compte</div>
        <p style="margin:0 0 0.9rem; color:var(--text-muted); font-size:0.88rem; line-height:1.5;">Connectez-vous avec votre adresse e-mail et votre mot de passe pour suivre la validation de votre compte. En attendant, vous pouvez modifier vos informations depuis votre profil.</p>
        <form action="login.php" method="POST" style="margin:0;">
            <div class="form-group">
                <label for="suivi-email" class="form-label">Adresse e-mail</label>
                <input type="email" id="suivi-email" name="email" class="form-input" value="' . $e . '" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="suivi-password" class="form-label">Mot de passe</label>
                <input type="password" id="suivi-password" name="password" class="form-input" placeholder="Votre mot de passe" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.4rem;">Se connecter et suivre mon compte</button>
        </form>
    </div>
    <p style="text-align:center; margin:1rem 0 0;"><a href="index.php" style="color:var(--text-muted); font-size:0.85rem; text-decoration:none;">← Retour à l\'accueil</a></p>
</div>';
}
