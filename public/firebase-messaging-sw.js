// Firebase JS SDK v9+ compat build — service workers can't use ES module imports the way
// the page does, so Firebase's documented SW pattern loads the *-compat scripts here.
importScripts('https://www.gstatic.com/firebasejs/11.1.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/11.1.0/firebase-messaging-compat.js');

var params = new URLSearchParams(self.location.search);
firebase.initializeApp({
    apiKey: params.get('apiKey'),
    projectId: params.get('projectId'),
    messagingSenderId: params.get('messagingSenderId'),
    appId: params.get('appId'),
});

const messaging = firebase.messaging();
messaging.onBackgroundMessage(function (payload) {
    const notification = payload.data || {};
    const title = notification.title || '';
    const options = {
        body: notification.body || notification.message || '',
        icon: notification.icon,
        data: { click_action: notification.click_action || notification.id || '' },
    };
    return self.registration.showNotification(title, options);
});
