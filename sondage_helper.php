<?php
/**
 * Touba Lyon 2026 - Sondages des commissions.
 *
 * Un responsable de commission crée un sondage (question + choix), le publie : tous les
 * membres validés peuvent y répondre. À la création, il choisit qui voit les résultats :
 *   - 'tous'   : tous les membres ;
 *   - 'bureau' : seulement le bureau = les responsables de commission.
 * Le créateur et les responsables de la commission du sondage voient toujours les résultats.
 */

const SONDAGE_VISIBILITE_TOUS = 'tous';
const SONDAGE_VISIBILITE_BUREAU = 'bureau';

/** Le membre fait-il partie du bureau (responsable d'au moins une commission) ? */
function sondage_est_bureau(PDO $pdo, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE membre_id = ? LIMIT 1");
        $st->execute([$membreId]);
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/** Le membre gère-t-il ce sondage (auteur ou responsable de sa commission) ? */
function sondage_peut_gerer(PDO $pdo, array $sondage, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    if ((int) ($sondage['auteur_id'] ?? 0) === $membreId) { return true; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $st->execute([(int) ($sondage['commission_id'] ?? 0), $membreId]);
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/** Le membre peut-il voir les résultats de ce sondage ? */
function sondage_peut_voir_resultats(PDO $pdo, array $sondage, int $membreId, bool $estBureau): bool
{
    if (($sondage['resultats_visibilite'] ?? '') !== SONDAGE_VISIBILITE_BUREAU) { return true; }
    return $estBureau || sondage_peut_gerer($pdo, $sondage, $membreId);
}

/** Le sondage est-il ouvert aux réponses (publié, non clôturé, date de fin non dépassée) ? */
function sondage_est_ouvert(array $sondage): bool
{
    if (($sondage['statut'] ?? '') !== 'publie') { return false; }
    $fin = (string) ($sondage['date_fin'] ?? '');
    return $fin === '' || $fin >= date('Y-m-d');
}

/** Options d'un sondage avec le nombre de voix : [['id','libelle','nb'], ...]. */
function sondage_options(PDO $pdo, int $sondageId): array
{
    $st = $pdo->prepare("SELECT o.id, o.libelle, (SELECT COUNT(*) FROM sondage_votes v WHERE v.option_id = o.id) AS nb
                         FROM sondage_options o WHERE o.sondage_id = ? ORDER BY o.position ASC, o.id ASC");
    $st->execute([$sondageId]);
    return $st->fetchAll();
}

/** Nombre de membres ayant répondu. */
function sondage_nb_participants(PDO $pdo, int $sondageId): int
{
    $st = $pdo->prepare("SELECT COUNT(DISTINCT membre_id) FROM sondage_votes WHERE sondage_id = ?");
    $st->execute([$sondageId]);
    return (int) $st->fetchColumn();
}

/** Ids des options choisies par un membre. */
function sondage_mes_choix(PDO $pdo, int $sondageId, int $membreId): array
{
    $st = $pdo->prepare("SELECT option_id FROM sondage_votes WHERE sondage_id = ? AND membre_id = ?");
    $st->execute([$sondageId, $membreId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Votants par option (pour les responsables, sondage non anonyme) : [option_id => [noms]]. */
function sondage_votants(PDO $pdo, int $sondageId): array
{
    $st = $pdo->prepare("SELECT v.option_id, TRIM(CONCAT(COALESCE(m.prenom,''), ' ', COALESCE(m.nom,''))) AS nom
                         FROM sondage_votes v JOIN membres m ON m.id = v.membre_id
                         WHERE v.sondage_id = ? ORDER BY m.nom ASC, m.prenom ASC");
    $st->execute([$sondageId]);
    $r = [];
    foreach ($st->fetchAll() as $row) { $r[(int) $row['option_id']][] = (string) $row['nom']; }
    return $r;
}

/** Barres de résultats (HTML). */
function sondage_resultats_html(array $options, int $participants): string
{
    $totalVoix = 0;
    foreach ($options as $o) { $totalVoix += (int) $o['nb']; }
    $max = 0;
    foreach ($options as $o) { $max = max($max, (int) $o['nb']); }
    $h = '<div class="sd-res">';
    foreach ($options as $o) {
        $nb = (int) $o['nb'];
        $pct = $participants > 0 ? (int) round($nb * 100 / $participants) : 0;
        $h .= '<div class="sd-res-row' . ($nb > 0 && $nb === $max ? ' sd-top' : '') . '">'
            . '<div class="sd-res-top"><span class="sd-res-lib">' . htmlspecialchars((string) $o['libelle']) . '</span>'
            . '<span class="sd-res-nb">' . $nb . ' · ' . $pct . ' %</span></div>'
            . '<div class="sd-bar"><div class="sd-bar-fill" style="width:' . $pct . '%;"></div></div></div>';
    }
    $h .= '<div class="sd-res-total">👥 ' . $participants . ' participant' . ($participants > 1 ? 's' : '') . '</div></div>';
    return $h;
}

/** Style commun des pages de sondage. */
function sondage_css(): string
{
    return <<<'CSS'
<style>
    .sd-wrap { max-width: 860px; margin: 1.5rem auto; }
    .sd-card { border-radius: 12px; padding: 1.2rem 1.4rem; margin-bottom: 1.2rem; }
    .sd-q { color: var(--white); font-size: 1.12rem; font-weight: 800; margin: 0 0 0.3rem; line-height: 1.35; }
    .sd-meta { color: var(--text-muted); font-size: 0.8rem; display: flex; flex-wrap: wrap; gap: 0.35rem 0.8rem; margin-bottom: 0.6rem; }
    .sd-desc { color: var(--white); font-size: 0.92rem; line-height: 1.5; margin: 0.3rem 0 0.8rem; white-space: pre-line; }
    .sd-badge { display: inline-block; font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.6rem; border-radius: 999px; border: 1px solid var(--glass-border); color: var(--text-muted); }
    .sd-badge.ok { color: #7bd8a6; border-color: rgba(37,211,102,0.4); background: rgba(37,211,102,0.1); }
    .sd-badge.gold { color: var(--gold); border-color: rgba(212,175,55,0.45); background: rgba(212,175,55,0.1); }
    .sd-badge.off { color: #fca5a5; border-color: rgba(191,33,33,0.4); background: rgba(191,33,33,0.1); }
    .sd-opt { display: flex; align-items: center; gap: 0.7rem; padding: 0.8rem 0.9rem; margin-bottom: 0.5rem; border: 1px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.03); cursor: pointer; color: var(--white); font-size: 0.95rem; min-height: 52px; transition: border-color .15s, background .15s; }
    .sd-opt:hover { border-color: var(--accent); }
    .sd-opt:has(input:checked) { border-color: var(--accent); background: rgba(212,175,55,0.1); }
    .sd-opt input[type=radio] { width: 22px; height: 22px; accent-color: var(--accent); flex: 0 0 auto; margin: 0; }
    .sd-opt input[type=checkbox] { flex: 0 0 auto; margin: 0; }
    .sd-res-row { margin-bottom: 0.7rem; }
    .sd-res-top { display: flex; justify-content: space-between; gap: 0.6rem; color: var(--white); font-size: 0.92rem; margin-bottom: 0.25rem; }
    .sd-res-nb { color: var(--text-muted); font-weight: 700; white-space: nowrap; }
    .sd-bar { height: 12px; border-radius: 999px; background: rgba(255,255,255,0.08); overflow: hidden; }
    .sd-bar-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, rgba(212,175,55,0.55), var(--accent)); transition: width .5s; }
    .sd-top .sd-res-lib { color: var(--gold); font-weight: 800; }
    .sd-res-total { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.3rem; }
    .sd-note { color: var(--text-muted); font-size: 0.85rem; padding: 0.7rem 0.9rem; border: 1px dashed var(--glass-border); border-radius: 10px; }
    .sd-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.9rem; }
    .sd-votants { margin-top: 0.7rem; }
    .sd-votants summary { cursor: pointer; color: var(--text-muted); font-size: 0.85rem; }
    .sd-votants p { margin: 0.35rem 0 0; font-size: 0.85rem; color: var(--white); }
    .sd-grp > summary { cursor: pointer; list-style: none; font-weight: 800; color: var(--white); font-size: 1.05rem; padding: 0.2rem 0; display: flex; align-items: center; gap: 0.5rem; }
    .sd-grp > summary::-webkit-details-marker { display: none; }
    .sd-grp { margin-bottom: 1.1rem; }
    @media (max-width: 600px) { .sd-card { padding: 1rem; } .sd-q { font-size: 1.05rem; } }
</style>
CSS;
}

/** Style supplémentaire du formulaire de création. */
function sondage_form_css(): string
{
    return <<<'CSS'
<style>
        .sd-vis { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .sd-vis .sd-opt { margin: 0; align-items: flex-start; }
        .sd-vis small { display: block; color: var(--text-muted); font-size: 0.78rem; margin-top: 0.15rem; }
        .sd-optrow { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center; }
        .sd-optrow input { flex: 1; min-width: 0; }
        .sd-optrow button { flex: 0 0 auto; width: 42px; height: 42px; border-radius: 10px; border: 1px solid rgba(191,33,33,0.4); background: rgba(191,33,33,0.12); color: #fca5a5; cursor: pointer; font-size: 1rem; }
        .sd-checks { display: flex; flex-wrap: wrap; gap: 0.6rem; }
        .sd-checks .sd-opt { margin: 0; flex: 1 1 220px; }
        @media (max-width: 600px) { .sd-vis { grid-template-columns: 1fr; } }
</style>
CSS;
}

/**
 * Formulaire de création d'un sondage (réservé aux responsables de commission).
 * $retour : page où revenir après la création (« sondages » ou « gestion »).
 * $prefill : valeurs à remettre après une erreur (question, description, options, commission_id).
 */
function sondage_formulaire_creation(array $mesCommissions, string $csrf, string $actionUrl = 'sondage_gestion.php', string $retour = 'gestion', array $prefill = [], bool $ouvert = false): string
{
    $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
    $editId = (int) ($prefill['edit_id'] ?? 0); // > 0 : modification d'un brouillon
    $dateFin = (string) ($prefill['date_fin'] ?? '');
    ob_start();
    ?>
                    <details class="glass-card sd-card"<?php echo $ouvert ? ' open' : ''; ?>>
                        <summary style="cursor:pointer; list-style:none; color:var(--white); font-size:1.15rem; font-weight:800;"><?php echo $editId > 0 ? '✏️ Modifier le brouillon' : '➕ Nouveau sondage'; ?></summary>
                        <form method="POST" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES); ?>" style="margin-top:1rem;" id="sd-form">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update' : 'create'; ?>"><?php if ($editId > 0): ?><input type="hidden" name="sondage_id" value="<?php echo $editId; ?>"><?php endif; ?><input type="hidden" name="retour" value="<?php echo htmlspecialchars($retour, ENT_QUOTES); ?>">
                            <?php if (count($mesCommissions) > 1): ?>
                                <div class="form-group"><label class="form-label">Commission</label>
                                    <select name="commission_id" class="form-input" required>
                                        <?php foreach ($mesCommissions as $c): ?><option value="<?php echo (int) $c['id']; ?>"<?php echo (int) ($prefill['commission_id'] ?? 0) === (int) $c['id'] ? ' selected' : ''; ?>><?php echo $h($c['nom']); ?></option><?php endforeach; ?>
                                    </select></div>
                            <?php else: ?>
                                <input type="hidden" name="commission_id" value="<?php echo (int) $mesCommissions[0]['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group"><label class="form-label">Question <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="question" class="form-input" maxlength="255" required placeholder="Ex : Quel jour pour la sortie annuelle ?" value="<?php echo $h($prefill['question'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Précisions (facultatif)</label>
                                <textarea name="description" class="form-input" rows="2" placeholder="Quelques mots pour expliquer le sondage…"><?php echo $h($prefill['description'] ?? ''); ?></textarea></div>
                            <div class="form-group"><label class="form-label">Choix de réponse <span style="color:var(--danger)">*</span> <span style="color:var(--text-muted); font-weight:400;">(2 minimum)</span></label>
                                <div id="sd-options"></div>
                                <button type="button" class="btn btn-secondary btn-sm" id="sd-add">➕ Ajouter un choix</button></div>
                            <div class="form-group"><label class="form-label">Options</label>
                                <div class="sd-checks">
                                    <label class="sd-opt"><input type="checkbox" name="choix_multiple" value="1"<?php echo !empty($prefill['choix_multiple']) ? ' checked' : ''; ?>> <span>☑️ Plusieurs réponses possibles</span></label>
                                    <label class="sd-opt"><input type="checkbox" name="anonyme" value="1"<?php echo !empty($prefill['anonyme']) ? ' checked' : ''; ?>> <span>🕶️ Réponses anonymes</span></label>
                                </div></div>
                            <div class="form-group"><label class="form-label">👁️ Qui voit les résultats ?</label>
                                <div class="sd-vis">
                                    <label class="sd-opt"><input type="radio" name="resultats_visibilite" value="tous"<?php echo ($prefill['resultats_visibilite'] ?? 'tous') !== 'bureau' ? ' checked' : ''; ?>><span>Tous les membres<small>Chacun voit les résultats</small></span></label>
                                    <label class="sd-opt"><input type="radio" name="resultats_visibilite" value="bureau"<?php echo ($prefill['resultats_visibilite'] ?? '') === 'bureau' ? ' checked' : ''; ?>><span>Le bureau seulement<small>Responsables de commission</small></span></label>
                                </div>
                                <p style="color:var(--text-muted); font-size:0.78rem; margin:0.4rem 0 0;">Vous-même et les responsables de la commission voyez toujours les résultats.</p></div>
                            <div class="form-group"><label class="form-label">⏳ Date de fin (facultatif)</label>
                                <input type="date" name="date_fin" class="form-input" value="<?php echo $h($dateFin); ?>"<?php echo ($dateFin === '' || $dateFin >= date('Y-m-d')) ? ' min="' . date('Y-m-d') . '"' : ''; ?>></div>
                            <div class="sd-actions">
                                <button type="submit" name="publier" value="1" class="btn btn-primary">📣 Publier maintenant</button>
                                <button type="submit" class="btn btn-secondary"><?php echo $editId > 0 ? '💾 Enregistrer les modifications' : '📝 Enregistrer en brouillon'; ?></button><?php if ($editId > 0): ?><a href="sondage_gestion.php" class="btn btn-secondary">Annuler</a><?php endif; ?>
                            </div>
                        </form>
                    </details>
<script>
        (function () {
            var box = document.getElementById('sd-options');
            var add = document.getElementById('sd-add');
            var prefill = <?php echo json_encode(array_values(array_filter(array_map('strval', (array) ($prefill['options'] ?? [])), 'strlen')), JSON_UNESCAPED_UNICODE); ?>;
            function row(val) {
                var d = document.createElement('div');
                d.className = 'sd-optrow';
                var i = document.createElement('input');
                i.type = 'text'; i.name = 'options[]'; i.className = 'form-input'; i.maxLength = 200; i.value = val || '';
                i.placeholder = 'Choix ' + (box.children.length + 1);
                var b = document.createElement('button');
                b.type = 'button'; b.textContent = '✕'; b.setAttribute('aria-label', 'Retirer ce choix');
                b.addEventListener('click', function () { if (box.children.length > 2) { d.remove(); renum(); } });
                d.appendChild(i); d.appendChild(b); box.appendChild(d);
            }
            function renum() { Array.prototype.forEach.call(box.querySelectorAll('input'), function (x, n) { if (!x.value) { x.placeholder = 'Choix ' + (n + 1); } }); }
            (prefill.length >= 2 ? prefill : ['', '']).forEach(row);
            add.addEventListener('click', function () {
                if (box.children.length >= 12) { return; }
                row('');
                box.lastChild.querySelector('input').focus();
            });
        })();
</script>
<?php
    return (string) ob_get_clean();
}
