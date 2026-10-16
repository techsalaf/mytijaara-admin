importScripts("https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js");
importScripts("https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js");

firebase.initializeApp({
    apiKey: "AIzaSyB0xtfJMflFm_2pXdxo5sMPncPQgyE9AAM",
    authDomain: "mytijaara-21638.firebaseapp.com",
    projectId: "mytijaara-21638",
    storageBucket: "mytijaara-21638.firebasestorage.app",
    messagingSenderId: "1017926959065",
    appId: "1:1017926959065:web:fee8e7af199a1d677e23c8",
    measurementId: "G-6XF74RVXXW"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
    const data = payload.data || {};
    const title = data.title || (payload.notification && payload.notification.title) || "Notification";
    const body  = data.body  || (payload.notification && payload.notification.body)  || "";
    const image = data.image || (payload.notification && payload.notification.image) || undefined;

    self.registration.showNotification(title, {
        body,
        icon: image,
        data,
    });
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const data = event.notification.data || {};
    const url = resolveTargetUrl(data);

    event.waitUntil(
        self.clients
            .matchAll({ type: "window", includeUncontrolled: true })
            .then((windowClients) => {
                const existing = windowClients.find((c) => c.url.startsWith(self.location.origin));
                if (existing) {
                    existing.focus();
                    return existing.navigate(url);
                }
                return self.clients.openWindow(url);
            }),
    );
});

function resolveTargetUrl(data) {
    const base = self.location.origin;
    if (data && data.type === "order_status" && data.order_id) {
        return base + "/profile?page=orders&orderId=" + encodeURIComponent(data.order_id);
    }
    if (data && data.type === "message") {
        return base + "/profile?page=inbox";
    }
    return base + "/";
}