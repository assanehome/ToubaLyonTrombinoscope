<?php
/**
 * Touba Lyon 2026 - Responsables d'une fonction (Guddi Àjjuma, Lecture Coran…).
 *
 * Seuls les responsables désignés (et l'administrateur) ont accès à la fonction. Ils peuvent ajouter ou retirer
 * d'autres responsables avec la même fenêtre de sélection à deux listes que pour les responsables de commission.
 * Table : fonction_responsables (fonction, membre_id).
 */

/** Le membre est-il responsable désigné de cette fonction ? */
function fr_est_responsable(PDO $pdo, string $fonction, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM fonction_responsables WHERE fonction = ? AND membre_id = ? LIMIT 1");
        $st->execute([$fonction, $membreId]);
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Traite l'ajout / le retrait d'un responsable (appels AJAX de la fenêtre). À appeler avant le traitement
 * des autres actions de la page ; répond en JSON puis s'arrête si la requête concerne cette fonction.
 */
function fr_traiter_post(PDO $pdo, string $fonction, bool $peutGerer, int $membreCourant, bool $isAdmin, string $libelle = ''): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['fr_add', 'fr_remove'], true) || (string) ($_POST['fonction'] ?? '') !== $fonction) { return; }
    header('Content-Type: application/json; charset=UTF-8');
    $rep = ['ok' => false, 'error' => 'Action refusée.'];
    try {
        $rid = (int) ($_POST['membre_id'] ?? 0);
        if (!function_exists('csrf_validate') || !csrf_validate()) { $rep['error'] = 'Échec de validation de sécurité. Rechargez la page.'; }
        elseif (!$peutGerer) { $rep['error'] = 'Action réservée aux responsables.'; }
        elseif ($rid <= 0) { $rep['error'] = 'Choisissez un membre.'; }
        elseif ($action === 'fr_add') {
            $ok = $pdo->prepare("SELECT 1 FROM membres WHERE id = ? AND status = 'approved'");
            $ok->execute([$rid]);
            if ($ok->fetchColumn() === false) { $rep['error'] = 'Membre introuvable ou non validé.'; }
            else {
                $ins = $pdo->prepare("INSERT IGNORE INTO fonction_responsables (fonction, membre_id, ajoute_par) VALUES (?, ?, ?)");
                $ins->execute([$fonction, $rid, $membreCourant > 0 ? $membreCourant : null]);
                if ($ins->rowCount() > 0 && $rid !== $membreCourant && function_exists('troba_notify_membre')) {
                    troba_notify_membre($pdo, $rid, 'commission', '🛠️ Vous êtes responsable : ' . ($libelle !== '' ? $libelle : $fonction), 'Vous avez accès à cette fonction depuis votre menu « Gestion ».', 'index.php');
                }
                $rep = ['ok' => true];
            }
        } else {
            $n = $pdo->prepare("SELECT COUNT(*) FROM fonction_responsables WHERE fonction = ?");
            $n->execute([$fonction]);
            if ((int) $n->fetchColumn() <= 1 && !$isAdmin) { $rep['error'] = 'Il doit rester au moins un responsable.'; }
            else {
                $pdo->prepare("DELETE FROM fonction_responsables WHERE fonction = ? AND membre_id = ?")->execute([$fonction, $rid]);
                $rep = ['ok' => true];
            }
        }
    } catch (Exception $e) {
        error_log('Touba Lyon responsables : ' . $e->getMessage());
        $rep = ['ok' => false, 'error' => 'Une erreur technique est survenue.'];
    }
    echo json_encode($rep, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Bloc « Responsables » : groupe (fermé par défaut) avec la liste des responsables et le bouton « Gérer »
 * qui ouvre la fenêtre de sélection à deux listes. $urlPage : page qui traite les appels AJAX.
 */
function fr_bloc_html(PDO $pdo, string $fonction, string $titre, string $urlPage): string
{
    $resps = []; $tous = [];
    try {
        $st = $pdo->prepare("SELECT membre_id FROM fonction_responsables WHERE fonction = ?");
        $st->execute([$fonction]);
        $resps = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        foreach ($pdo->query("SELECT id, prenom, nom, photo_path FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll() as $m) {
            $ini = strtoupper(mb_substr((string) ($m['prenom'] ?? ''), 0, 1) . mb_substr((string) ($m['nom'] ?? ''), 0, 1));
            $tous[] = ['id' => (int) $m['id'], 'name' => trim($m['prenom'] . ' ' . $m['nom']), 'photo' => $m['photo_path'] ?? '', 'ini' => ($ini !== '' ? $ini : '?')];
        }
    } catch (Exception $e) { $resps = []; $tous = []; }
    $data = json_encode($tous, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    $sel = json_encode($resps);
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $f = htmlspecialchars($fonction, ENT_QUOTES);
    $t = htmlspecialchars($titre, ENT_QUOTES);
    $u = htmlspecialchars($urlPage, ENT_QUOTES);
    $n = count($resps);
    ob_start();
    ?>
<style>
    #fr-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
    #fr-modal.active { display: flex; }
    .fr-card { width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; background: linear-gradient(180deg,#123528,#0c241a); border: 1px solid rgba(212,175,55,0.25); border-radius: 12px; overflow: hidden; }
    .fr-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); flex-shrink: 0; }
    .fr-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
    .fr-x { background: rgba(255,255,255,0.15); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 50%; font-size: 1.1rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
    .fr-cols { display: flex; flex: 1; min-height: 0; }
    .fr-col { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .fr-col + .fr-col { border-left: 1px solid var(--glass-border); }
    .fr-col-head { padding: 0.75rem 1rem 0.5rem; flex-shrink: 0; }
    .fr-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; }
    .fr-col-head input { width: 100%; margin-top: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 12px; color: #fff; font-size: 0.85rem; padding: 0.5rem 0.8rem; box-sizing: border-box; }
    .fr-col-head input:focus { outline: none; border-color: var(--accent); }
    .fr-list { flex: 1; overflow-y: auto; padding: 0.5rem 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
    .fr-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.5rem; border: 1px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.03); }
    .fr-av { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .fr-av--ph { background: rgba(212,175,55,0.15); color: var(--gold); font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(212,175,55,0.35); }
    .fr-name { flex: 1; color: #fff; font-size: 0.88rem; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .fr-item button { flex-shrink: 0; border: 0; border-radius: 8px; width: 30px; height: 30px; font-size: 1.1rem; line-height: 1; cursor: pointer; }
    .fr-add { background: rgba(212,175,55,0.2); color: var(--gold); }
    .fr-rem { background: rgba(255,80,80,0.18); color: #ff8a8a; }
    .fr-empty { color: var(--text-muted); font-size: 0.82rem; font-style: italic; padding: 0.75rem 0.5rem; text-align: center; }
    .fr-foot { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.85rem 1.25rem; border-top: 1px solid var(--glass-border); flex-wrap: wrap; flex-shrink: 0; }
    .fr-count { font-size: 0.8rem; color: var(--text-muted); }
    .fr-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .fr-chip { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.25rem 0.75rem 0.25rem 0.3rem; border: 1px solid rgba(212,175,55,0.4); border-radius: 10px; background: rgba(255,255,255,0.05); color: var(--white); font-size: 0.88rem; font-weight: 600; }
    @media (max-width: 600px) { .fr-cols { flex-direction: column; } .fr-col + .fr-col { border-left: 0; border-top: 1px solid var(--glass-border); } .fr-list { max-height: 30vh; } }
</style>
<details class="glass-card fr-grp" style="margin-bottom:1rem;">
    <summary>👥 Responsables — <?php echo $t; ?> <span style="color:var(--text-muted); font-weight:600; font-size:0.8rem;">(<span id="fr-count"><?php echo (int) $n; ?></span>)</span></summary>
    <p style="color:var(--text-muted); font-size:0.85rem; margin:0.6rem 0;">Seuls les responsables désignés (et l'administrateur) ont accès à cette fonction. Ils peuvent ajouter ou retirer d'autres responsables.</p>
    <div class="fr-chips" id="fr-chips"></div>
    <button type="button" class="btn btn-primary btn-sm" style="margin-top:0.8rem;" onclick="openFr()">👥 Gérer les responsables</button>
</details>
<div id="fr-modal">
    <div class="fr-card">
        <div class="fr-head"><h3>Responsables — <?php echo $t; ?></h3><button type="button" class="fr-x" onclick="closeFr()" aria-label="Fermer">&times;</button></div>
        <div class="fr-cols">
            <div class="fr-col">
                <div class="fr-col-head"><div class="fr-title">Membres disponibles</div><input type="text" id="fr-search" placeholder="🔍 Rechercher un membre…"></div>
                <div class="fr-list" id="fr-available"></div>
            </div>
            <div class="fr-col">
                <div class="fr-col-head"><div class="fr-title">Responsables (<span id="fr-sel-count">0</span>)</div></div>
                <div class="fr-list" id="fr-selected"></div>
            </div>
        </div>
        <div class="fr-foot"><span class="fr-count" id="fr-avail-count"></span><button type="button" class="btn btn-primary btn-sm" onclick="closeFr()">Terminé</button></div>
    </div>
</div>
<script>
(function () {
    var ALL = <?php echo $data; ?>, SEL = new Set(<?php echo $sel; ?>), CSRF = <?php echo json_encode($csrf); ?>, BY = {};
    ALL.forEach(function (m) { BY[m.id] = m; });
    function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
    function av(m) { return m.photo ? '<img class="fr-av" src="uploads/' + encodeURIComponent(m.photo) + '" alt="">' : '<span class="fr-av fr-av--ph">' + esc(m.ini) + '</span>'; }
    function chips() {
        var ids = Array.from(SEL), el = document.getElementById('fr-chips');
        el.innerHTML = ids.length ? ids.map(function (id) { var m = BY[id]; return m ? '<span class="fr-chip">' + av(m) + '<span>🎖️ ' + esc(m.name) + '</span></span>' : ''; }).join('') : '<span style="color:var(--text-muted);">Aucun responsable désigné.</span>';
        document.getElementById('fr-count').textContent = ids.length;
    }
    function persist(add, id) {
        var p = new URLSearchParams();
        p.append('action', add ? 'fr_add' : 'fr_remove'); p.append('fonction', '<?php echo $f; ?>'); p.append('membre_id', id); p.append('csrf_token', CSRF);
        return fetch('<?php echo $u; ?>', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: p.toString(), credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) { throw new Error(j.error || 'Erreur'); } });
    }
    function render() {
        var term = norm(document.getElementById('fr-search').value), a = document.getElementById('fr-available'), s = document.getElementById('fr-selected'), na = 0, ns = 0;
        a.innerHTML = ''; s.innerHTML = '';
        ALL.forEach(function (m) {
            if (SEL.has(m.id)) { s.insertAdjacentHTML('beforeend', '<div class="fr-item"><span>' + av(m) + '</span><span class="fr-name">' + esc(m.name) + '</span><button type="button" class="fr-rem" data-id="' + m.id + '" aria-label="Retirer">&minus;</button></div>'); ns++; }
            else { if (term && norm(m.name).indexOf(term) === -1) { return; } a.insertAdjacentHTML('beforeend', '<div class="fr-item"><span>' + av(m) + '</span><span class="fr-name">' + esc(m.name) + '</span><button type="button" class="fr-add" data-id="' + m.id + '" aria-label="Ajouter">+</button></div>'); na++; }
        });
        if (!na) { a.innerHTML = '<div class="fr-empty">Aucun membre disponible.</div>'; }
        if (!ns) { s.innerHTML = '<div class="fr-empty">Aucun responsable.</div>'; }
        document.getElementById('fr-sel-count').textContent = ns;
        document.getElementById('fr-avail-count').textContent = na + ' disponible(s)';
    }
    function alerte(m) { if (window.modernAlert) { window.modernAlert(m, 'Erreur'); } else { alert(m); } }
    document.getElementById('fr-modal').addEventListener('click', function (e) {
        if (e.target === this) { closeFr(); return; }
        var b = e.target.closest ? e.target.closest('button[data-id]') : null;
        if (!b) { return; }
        var id = parseInt(b.getAttribute('data-id'), 10), add = b.classList.contains('fr-add');
        if (add) { SEL.add(id); } else { SEL.delete(id); }
        render(); chips();
        persist(add, id).catch(function (er) { if (add) { SEL.delete(id); } else { SEL.add(id); } render(); chips(); alerte((add ? "Échec de l'ajout : " : 'Échec du retrait : ') + er.message); });
    });
    document.getElementById('fr-search').addEventListener('input', render);
    window.openFr = function () { document.getElementById('fr-search').value = ''; render(); document.getElementById('fr-modal').classList.add('active'); };
    window.closeFr = function () { document.getElementById('fr-modal').classList.remove('active'); };
    chips();
})();
</script>
    <?php
    return (string) ob_get_clean();
}
