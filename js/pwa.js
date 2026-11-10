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

let installPrompt;
let $installApp = document.getElementById('installApp');
window.addEventListener('beforeinstallprompt', event => {
    if (!$installApp) {
        return;
    }
    event.preventDefault();
    installPrompt = event;
    $installApp.hidden = false;
});
$installApp?.addEventListener('click', async () => {
    if (!installPrompt) {
        return;
    }
    await installPrompt.prompt();
    installPrompt = null;
    $installApp.hidden = true;
});
window.addEventListener('appinstalled', () => {
    installPrompt = null;
    if ($installApp) {
        $installApp.hidden = true;
    }
});
