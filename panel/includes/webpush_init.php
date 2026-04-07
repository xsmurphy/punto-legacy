<?php
/**
 * Inicializa Web Push en el browser del usuario autenticado.
 * Incluir al final del <body> del panel, solo cuando hay USER_ID.
 *
 * Uso:
 *   <?php include 'includes/webpush_init.php'; ?>
 */
if (!defined('USER_ID') || !VAPID_PUBLIC_KEY) return;
?>
<script>
(function() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    const vapidPublicKey = '<?= VAPID_PUBLIC_KEY ?>';

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw     = window.atob(base64);
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }

    navigator.serviceWorker.register('/sw-push.js').then(function(reg) {
        return reg.pushManager.getSubscription().then(function(existing) {
            if (existing) return existing; // ya suscripto

            // Solo pedir permiso si el usuario no lo denegó antes
            if (Notification.permission === 'denied') return null;

            return reg.pushManager.subscribe({
                userVisibleOnly:      true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
            });
        });
    }).then(function(sub) {
        if (!sub) return;

        const key  = sub.getKey('p256dh');
        const auth = sub.getKey('auth');

        fetch('/API/subscribe_push.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                p256dh:   key  ? btoa(String.fromCharCode(...new Uint8Array(key)))  : '',
                auth:     auth ? btoa(String.fromCharCode(...new Uint8Array(auth))) : '',
            })
        });
    }).catch(function() {
        // Silencioso — push no es crítico
    });
})();
</script>
