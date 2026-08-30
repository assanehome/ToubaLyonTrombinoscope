/**
 * Trombinoscope Touba Lyon - service worker
 *
 * Rôles :
 *   - permettre l'installation sur l'écran d'accueil (PWA) ;
 *   - garder sous la main l'habillage (CSS, logo, icônes) et une page de repli,
 *     pour que l'application s'ouvre proprement même sans réseau ;
 *   - recevoir les notifications « application fermée » (Web Push) et les
 *     afficher, puis ouvrir la bonne page au clic.
 *
 * Les pages sont toujours demandées au réseau (elles contiennent des données
 * de membres) : jamais de contenu périmé servi depuis le cache. Sans réseau,
 * une page de repli explique la situation.
 */
const VERSION = 'v7';
const CACHE = 'trombinoscope-statique-' + VERSION;
const REPLI = 'hors-ligne.html';
const STATIQUES = [
    REPLI,
    'style.css',
    'modern-select.js',
    'touba_lyon_logo.png',
    'icone_192.png',
    'icone_512.png',
    'manifest.json'
];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE)
            .then(function (c) { return c.addAll(STATIQUES); })
            .catch(function () { /* un fichier absent ne doit pas bloquer l'installation */ })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (noms) {
            return Promise.all(noms.map(function (n) {
                return n === CACHE ? null : caches.delete(n);
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

// ---------------------------------------------------------------------------
// Notifications reçues du service de notification (site fermé)
// ---------------------------------------------------------------------------
self.addEventListener('push', function (e) {
    let donnees = { titre: 'Dahira Touba Lyon', texte: '', lien: 'index.php' };
    if (e.data) {
        try {
            const recu = e.data.json();
            donnees.titre = recu.titre || donnees.titre;
            donnees.texte = recu.texte || '';
            donnees.lien = recu.lien || donnees.lien;
        } catch (err) {
            donnees.texte = e.data.text();
        }
    }

    e.waitUntil(
        self.registration.showNotification(donnees.titre, {
            body: donnees.texte,
            icon: 'icone_192.png',
            badge: 'icone_192.png',
            lang: 'fr',
            tag: 'trombi-push',
            renotify: true,
            data: { lien: donnees.lien }
        })
    );
});

// Clic sur une notification : on revient sur l'onglet déjà ouvert s'il existe,
// sinon on ouvre la page concernée.
self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    const cible = (e.notification.data && e.notification.data.lien) || 'index.php';
    const url = new URL(cible, self.location).href;

    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (fenetres) {
            for (const f of fenetres) {
                if (f.url === url && 'focus' in f) { return f.focus(); }
            }
            for (const f of fenetres) {
                if ('navigate' in f && 'focus' in f) {
                    return f.navigate(url).then(function (w) { return w ? w.focus() : null; });
                }
            }
            return self.clients.openWindow(url);
        })
    );
});

// La page peut demander l'activation immédiate d'une nouvelle version
self.addEventListener('message', function (e) {
    if (e.data === 'passer-a-la-nouvelle-version') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', function (e) {
    const req = e.request;
    if (req.method !== 'GET') {
        return; // envois de formulaires : rien à mettre en cache
    }
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    const estStatique = /\.(css|js|png|jpg|jpeg|webp|svg|ico|woff2?)$/i.test(url.pathname)
        || url.pathname.endsWith('manifest.json');

    if (estStatique) {
        // Cache d'abord : ces fichiers ne changent que lors d'une mise à jour
        e.respondWith(
            caches.match(req).then(function (rep) {
                return rep || fetch(req).then(function (reseau) {
                    const copie = reseau.clone();
                    caches.open(CACHE).then(function (c) { c.put(req, copie); });
                    return reseau;
                });
            })
        );
        return;
    }

    // Pages : réseau d'abord ; sans réseau, page de repli.
    if (req.mode === 'navigate') {
        e.respondWith(
            fetch(req).catch(function () {
                return caches.match(REPLI).then(function (rep) {
                    return rep || new Response('Hors ligne', {
                        status: 503,
                        headers: { 'Content-Type': 'text/plain; charset=UTF-8' }
                    });
                });
            })
        );
        return;
    }

    e.respondWith(fetch(req).catch(function () { return caches.match(req); }));
});
