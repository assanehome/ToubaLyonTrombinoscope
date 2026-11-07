<?php
/**
 * Touba Lyon 2026 - Consentement RGPD (inscription_rapide.php, adhesion.php)
 *
 * Bloc d'information et case à cocher obligatoire pour le traitement des
 * données personnelles et de la photo. La preuve du consentement (date et
 * version du texte) est enregistrée dans la table membres.
 */

/** Version du texte affiché : à changer quand le texte évolue. */
const RGPD_VERSION = '2026-11';

/** Bloc HTML du consentement (information + case à cocher obligatoire). */
function rgpd_consent_html(): string
{
    return <<<'HTML'
<div class="rgpd-box" style="margin:1.4rem 0 0.4rem; padding:1rem 1.1rem; border:1px solid rgba(212,175,55,0.45); border-radius:10px; background:rgba(255,255,255,0.03);">
    <div style="color:var(--gold); font-weight:800; margin-bottom:0.5rem;">🔒 Protection de vos données (RGPD)</div>
    <label style="display:flex; align-items:flex-start; gap:0.7rem; cursor:pointer; color:var(--white); font-size:0.92rem; line-height:1.5;">
        <input type="checkbox" name="rgpd_accepte" id="rgpd_accepte" value="1" required style="margin-top:2px;">
        <span>J'accepte que le Dahira Touba Lyon traite mes données personnelles et ma photo, comme indiqué ci-dessous. <span style="color:var(--danger)">*</span></span>
    </label>
    <details style="margin-top:0.7rem;">
        <summary style="cursor:pointer; color:var(--gold); font-size:0.85rem; font-weight:700;">Lire le détail du traitement de mes données</summary>
        <div style="margin-top:0.6rem; color:var(--text-muted); font-size:0.84rem; line-height:1.6;">
            <p style="margin:0 0 0.5rem;"><strong style="color:var(--white);">Responsable du traitement :</strong> Dahira Touba Lyon (Mubawwa-A-Sidqin).</p>
            <p style="margin:0 0 0.5rem;"><strong style="color:var(--white);">Pourquoi ces données :</strong> gérer les adhésions et la vie du Dahira (membres, commissions, Kurels, Dahira, Guddi, Wadial Magal), vous contacter (e-mail, notifications, WhatsApp) et suivre les cotisations.</p>
            <p style="margin:0 0 0.5rem;"><strong style="color:var(--white);">Quelles données :</strong> nom, prénom, e-mail, téléphone, adresse, statut, profession, année d'intégration, informations saisies dans le formulaire, mot de passe (conservé sous forme chiffrée) et photo.</p>
            <p style="margin:0 0 0.5rem;"><strong style="color:var(--white);">Votre photo :</strong> elle sert à vous identifier. Une fois votre inscription validée, elle peut apparaître dans le trombinoscope du site et dans les pages des membres.</p>
            <p style="margin:0 0 0.5rem;"><strong style="color:var(--white);">Qui y a accès :</strong> les responsables et administrateurs du Dahira, dans le cadre de leurs missions. Vos données ne sont ni vendues ni cédées à des tiers.</p>
            <p style="margin:0 0 0.5rem;"><strong style="color:var(--white);">Combien de temps :</strong> pendant la durée de votre adhésion, puis jusqu'à votre demande de suppression.</p>
            <p style="margin:0;"><strong style="color:var(--white);">Vos droits :</strong> accès, rectification, effacement, opposition, limitation et retrait de votre consentement à tout moment, en contactant le secrétariat du Dahira. Vous pouvez aussi introduire une réclamation auprès de la CNIL (cnil.fr).</p>
        </div>
    </details>
</div>
HTML;
}
