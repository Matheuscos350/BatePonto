/* Service worker do Ponto: deixa o site instalável e mostra uma página offline amigável.
 * NÃO guarda páginas nem dados pessoais em cache (tudo que é PHP vai sempre à rede). */
const VERSION = 'ponto-v1';
const STATIC = ['offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/favicon-32.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(STATIC)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;                       // envios (ponto, gastos, login) sempre pela rede
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (req.mode === 'navigate') {                          // páginas: rede; sem internet → página offline
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  if (url.pathname.includes('/icons/')) {                 // só os ícones vêm do cache
    e.respondWith(caches.match(req).then(r => r || fetch(req)));
  }
});
