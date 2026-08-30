<?php
$file = $_GET['file'] ?? '';

// Basic security validation: Only allow files within Coran_pdf/
if (strpos($file, 'Coran_pdf/') !== 0 || strpos($file, '..') !== false) {
    die("Fichier PDF non valide ou introuvable.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecture du Coran - Touba Lyon</title>
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
            <button class="pdf-btn" onclick="goBack();">◀ Retour</button>
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
            pageNum = 1,
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
