if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/?asset=pwa/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {
            let $notice = document.createElement('p');
            $notice.className = 'viewnav__empty';
            $notice.setAttribute('role', 'status');
            $notice.textContent = 'Der Offline-Modus ist derzeit nicht verfügbar.';
            (document.querySelector('main') || document.body).append($notice);
        });
    });
}
