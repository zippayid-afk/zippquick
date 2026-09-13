import { initializeApp } from 'firebase/app';
import { getMessaging, getToken, onMessage, isSupported } from 'firebase/messaging';
import toastr from 'toastr';

async function initFcm() {
    const cfg = window.firebaseConfig;
    if (!cfg || !cfg.apiKey || !cfg.projectId || !cfg.messagingSenderId || !cfg.appId) return;
    if (!('Notification' in window)) return;
    if (!(await isSupported())) return;

    const base = window.baseUrl || '';
    const app = initializeApp(cfg);
    const messaging = getMessaging(app);

    try {
        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            let swReg;
            if ('serviceWorker' in navigator) {
                // Pass the project config to the SW so its Firebase config matches this panel.
                const swParams = new URLSearchParams(cfg).toString();
                
                swReg = await navigator.serviceWorker.register('/firebase-messaging-sw.js?' + swParams);
                // Ensure a worker is ACTIVE before getToken (inactive worker has no pushManager).
                await navigator.serviceWorker.ready;
            }
            const token = await getToken(messaging, {
                vapidKey: window.firebaseVapidKey || undefined,
                serviceWorkerRegistration: swReg,
            });
            window.panelFcmToken = token || '';
        }
    } catch (error) {
        console.error('FCM registration failed:', error);
    }

    onMessage(messaging, (payload) => {
        const d = payload.data || {};
        if (d.type === 'new_order') {
            new Audio(base + '/assets/order_sound.mp3').play();
            toastr.options = {
                onclick: () => { window.location.href = base + '/orders?order_id=' + d.id; },
                showDuration: '60000',
                hideDuration: '20000',
                timeOut: '60000',
                extendedTimeOut: '10000',
                closeButton: true,
            };
            toastr.info(d.message, d.title);
        }

        // Don't raise a browser notification on a backgrounded tab.
        if (document.hidden) return;

        // For chat pushes, if this admin is already viewing that conversation, skip the
        // notification (the open thread's realtime handler already shows the message).
        if (d.type === 'chat' && String(d.id) === String(window.activeChatConversationId || '')) return;

        const notifOptions = { body: d.body, icon: d.icon };
        // Show the attachment preview when the message carries an image URL.
        if (d.image) notifOptions.image = d.image;
        const browserNotif = new Notification(d.title, notifOptions);
        browserNotif.onclick = () => {
            window.focus();
            window.location.href = base + '/orders?order_id=' + d.id;
        };
    });
}

initFcm();
