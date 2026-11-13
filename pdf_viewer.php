<?php
/**
 * Touba Lyon 2026 - Liseuse PDF : lire un document sans quitter le site
 *
 * Deux dossiers seulement sont lisibles ici : le Coran (Coran_pdf/) et les
 * documents joints aux séances (uploads/). Tout autre chemin est refusé, et
 * un chemin qui tente de remonter d'un cran encore plus.
 *
 * Paramètres : file (le document), titre (le libellé affiché) et retour (la
 * page du site où ramène le bouton « Retour »).
 */
$file = (string) ($_GET['file'] ?? '');
$titre = trim((string) ($_GET['titre'] ?? ''));
$retour = trim((string) ($_GET['retour'] ?? ''));
$pageDebut = max(1, min(99999, (int) ($_GET['page'] ?? 1))); // page sur laquelle le document s'ouvre

$dossierOk = strpos($file, 'Coran_pdf/') === 0 || strpos($file, 'uploads/') === 0;
$lisible = $dossierOk
    && strpos($file, '..') === false
    && strpos($file, "\0") === false
    && strpos($file, '\\') === false
    && strtolower(substr($file, -4)) === '.pdf'
    && is_file(__DIR__ . '/' . $file);

// Le retour reste dans le site : une page d'ici, jamais une adresse extérieure.
if (!preg_match('~^[A-Za-z0-9_-]+\.php(\?[A-Za-z0-9_=&%.:+-]*)?$~', $retour)) {
    $retour = '';
}

if ($titre === '') {
    $titre = $lisible ? basename($file) : 'Document';
}
if (mb_strlen($titre) > 70) {
    $titre = mb_substr($titre, 0, 70) . '…';
}

if (!$lisible) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Document introuvable - Touba Lyon</title>
        <style>
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
                   background: #0a110e; color: #e8e2d0; font-family: 'Inter', system-ui, -apple-system, sans-serif; padding: 1.5rem; }
            .carte { max-width: 420px; text-align: center; border: 1px solid rgba(212,175,55,0.25);
                     border-radius: 12px; padding: 2rem 1.6rem; background: rgba(255,255,255,0.03); }
            h1 { color: #ffd873; font-size: 1.25rem; margin: 0 0 0.7rem; }
            p { color: #a9b5ad; line-height: 1.55; margin: 0 0 1.4rem; font-size: 0.92rem; }
            a { display: inline-block; text-decoration: none; color: #ffd873; font-weight: 600;
                border: 1px solid rgba(212,175,55,0.35); border-radius: 8px; padding: 0.55rem 1.1rem; }
        </style>
    </head>
    <body>
        <div class="carte">
            <h1>📄 Document introuvable</h1>
            <p>Ce document n'est plus en ligne, ou son adresse n'est pas celle d'un document du site.</p>
            <a href="<?php echo $retour !== '' ? htmlspecialchars($retour) : 'index.php'; ?>">◀ Retour</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($titre); ?> - Touba Lyon</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <style>
        body, html {
            margin: 0; padding: 0;
            background: #0a110e; /* Dark green/black theme from Touba Lyon */
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        .pdf-toolbar {
            background: rgba(20, 30, 24, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(212,175,55,0.2);
            padding: 0.8rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #fff;
            z-index: 100;
        }
        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pdf-btn {
            background: rgba(255,255,255,0.05);
            color: #ffd873;
            border: 1px solid rgba(212,175,55,0.3);
            border-radius: 6px;
            padding: 0.5rem 1rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .pdf-btn:hover { background: rgba(212,175,55,0.15); }
        .pdf-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        a.pdf-btn { text-decoration: none; display: inline-block; }
        .pdf-titre { color: #e8e2d0; font-size: 0.88rem; font-weight: 600; max-width: 38vw;
                     overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        
        #page_info { font-size: 1rem; font-weight: 600; color: #fff; margin: 0 0.5rem; min-width: 100px; text-align: center; }
        
        .pdf-viewer-container {
            flex: 1;
            overflow: auto;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 1rem;
        }
        #pdf-canvas {
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            background: #fff; /* Pages are usually white */
            transition: transform 0.2s ease-out;
            transform-origin: top center;
            max-width: 100%;
        }
        .loading-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(10, 17, 14, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #ffd873;
            font-size: 1.2rem;
            font-weight: bold;
            z-index: 200;
            backdrop-filter: blur(4px);
        }
        @media (max-width: 600px) {
            .pdf-toolbar { flex-wrap: wrap; justify-content: center; gap: 0.5rem; padding: 0.5rem; }
            .toolbar-group { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

    <div class="pdf-toolbar">
        <div class="toolbar-group">
            <?php if ($retour !== ''): ?>
                <a class="pdf-btn" href="<?php echo htmlspecialchars($retour); ?>">◀ Retour</a>
            <?php else: ?>
                <button class="pdf-btn" onclick="goBack();">◀ Retour</button>
            <?php endif; ?>
            <span class="pdf-titre"><?php echo htmlspecialchars($titre); ?></span>
        </div>
        <div class="toolbar-group">
            <button class="pdf-btn" id="prev_page">◀ Préc.</button>
            <span id="page_info">Page <span id="page_num">0</span> / <span id="page_count">0</span></span>
            <button class="pdf-btn" id="next_page">Suiv. ▶</button>
        </div>
        <div class="toolbar-group">
            <button class="pdf-btn" id="zoom_out">-</button>
            <button class="pdf-btn" id="zoom_in">+</button>
        </div>
    </div>

    <div class="pdf-viewer-container" id="viewer-container">
        <canvas id="pdf-canvas"></canvas>
    </div>

    <div class="loading-overlay" id="loading">Chargement du document...</div>

    <script>
        const url = <?php echo json_encode($file); ?>;
        
        let pdfDoc = null,
            pageNum = <?php echo (int) $pageDebut; ?>,
            pageIsRendering = false,
            pageNumIsPending = null,
            scale = 1.3,
            canvas = document.getElementById('pdf-canvas'),
            ctx = canvas.getContext('2d');

        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

        const renderPage = num => {
            pageIsRendering = true;

            pdfDoc.getPage(num).then(page => {
                const viewport = page.getViewport({ scale });
                canvas.height = viewport.height;
                canvas.width = viewport.width;

                const renderCtx = {
                    canvasContext: ctx,
                    viewport: viewport
                };

                page.render(renderCtx).promise.then(() => {
                    pageIsRendering = false;
                    
                    if(pageNumIsPending !== null) {
                        renderPage(pageNumIsPending);
                        pageNumIsPending = null;
                    }
                });

                document.getElementById('page_num').textContent = num;
            });
        };

        const queueRenderPage = num => {
            if(pageIsRendering) {
                pageNumIsPending = num;
            } else {
                renderPage(num);
            }
        };

        const showPrevPage = () => {
            if(pageNum <= 1) return;
            pageNum--;
            queueRenderPage(pageNum);
            document.getElementById('viewer-container').scrollTop = 0;
        };

        const showNextPage = () => {
            if(pageNum >= pdfDoc.numPages) return;
            pageNum++;
            queueRenderPage(pageNum);
            document.getElementById('viewer-container').scrollTop = 0;
        };

        const zoomIn = () => {
            if(scale >= 3.0) return;
            scale += 0.2;
            queueRenderPage(pageNum);
        };

        const zoomOut = () => {
            if(scale <= 0.5) return;
            scale -= 0.2;
            queueRenderPage(pageNum);
        };

        pdfjsLib.getDocument(url).promise.then(pdfDoc_ => {
            pdfDoc = pdfDoc_;
            document.getElementById('page_count').textContent = pdfDoc.numPages;
            if (pageNum > pdfDoc.numPages) { pageNum = pdfDoc.numPages; } // page demandée au-delà de la fin : dernière page
            document.getElementById('loading').style.display = 'none';
            renderPage(pageNum);
        }).catch(err => {
            document.getElementById('loading').innerHTML = 'Erreur lors du chargement du PDF.<br>' + err.message;
        });

        document.getElementById('prev_page').addEventListener('click', showPrevPage);
        document.getElementById('next_page').addEventListener('click', showNextPage);
        document.getElementById('zoom_in').addEventListener('click', zoomIn);
        document.getElementById('zoom_out').addEventListener('click', zoomOut);
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') showNextPage();
            if (e.key === 'ArrowLeft') showPrevPage();
        });

        // Navigation par glissement horizontal (mobile) : glisser vers la
        // gauche = page suivante, vers la droite = page précédente. On ignore
        // le geste vertical (défilement) et le geste à deux doigts (zoom), et
        // on laisse le panoramique fonctionner quand la page est zoomée.
        (function () {
            const zone = document.getElementById('viewer-container');
            if (!zone) return;
            let x0 = 0, y0 = 0, suivi = false;
            zone.addEventListener('touchstart', (e) => {
                if (e.touches.length !== 1) { suivi = false; return; }
                x0 = e.touches[0].clientX;
                y0 = e.touches[0].clientY;
                suivi = true;
            }, { passive: true });
            zone.addEventListener('touchend', (e) => {
                if (!suivi) return;
                suivi = false;
                const t = e.changedTouches && e.changedTouches[0];
                if (!t) return;
                const dx = t.clientX - x0;
                const dy = t.clientY - y0;
                // Uniquement un geste franchement horizontal
                if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
                // Page zoomée qui déborde : on laisse le panoramique horizontal
                if (zone.scrollWidth > zone.clientWidth + 4) return;
                if (dx < 0) { showNextPage(); } else { showPrevPage(); }
            }, { passive: true });
        })();

        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.close();
            }
        }
    </script>
</body>
</html>
