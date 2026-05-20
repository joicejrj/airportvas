// frontend/provider/push.js
// Web Push subscription helper for the provider PWA.
//
// Exposes window.initPushNotifications(token) and window.unsubscribePush(token).
// Written as plain script (no `type=module`) to match the existing
// frontend/provider/index.html structure.

(function () {
  'use strict';

  const API_BASE = '/api';

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw     = atob(base64);
    const out     = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  function authHeaders(token) {
    return {
      'Content-Type':  'application/json',
      'Authorization': 'Bearer ' + token,
    };
  }

  /**
   * Subscribe this device to push and register with the server.
   * Idempotent — safe to call on every login.
   *
   * @param {string} token  bearer token
   * @returns {Promise<boolean>} true on success
   */
  async function initPushNotifications(token) {
    if (!('serviceWorker' in navigator) ||
        !('PushManager'   in window)    ||
        !('Notification'  in window)) {
      console.info('[Push] Not supported on this browser');
      return false;
    }
    if (!token) {
      console.warn('[Push] No auth token, skipping');
      return false;
    }

    // 1) Permission
    let perm = Notification.permission;
    if (perm === 'default') {
      perm = await Notification.requestPermission();
    }
    if (perm !== 'granted') {
      console.info('[Push] Permission not granted:', perm);
      return false;
    }

    // 2) Wait for SW
    let reg;
    try {
      reg = await navigator.serviceWorker.ready;
    } catch (e) {
      console.warn('[Push] SW not ready', e);
      return false;
    }

    // 3) Reuse existing subscription or create one
    let sub = await reg.pushManager.getSubscription();

    if (!sub) {
      let vapidPublicKey;
      try {
        const r = await fetch(API_BASE + '/push/public-key');
        const j = await r.json();
        vapidPublicKey = j && j.data && j.data.publicKey;
      } catch (e) {
        console.error('[Push] Could not fetch VAPID key', e);
        return false;
      }
      if (!vapidPublicKey) {
        console.error('[Push] Server did not return a VAPID public key');
        return false;
      }

      try {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly:      true,
          applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        });
      } catch (err) {
        console.error('[Push] subscribe() failed:', err);
        return false;
      }
    }

    // 4) Send to backend (upsert)
    const raw = sub.toJSON();
    const body = {
      endpoint: raw.endpoint,
      p256dh:   raw.keys && raw.keys.p256dh,
      auth:     raw.keys && raw.keys.auth,
    };

    try {
      const res = await fetch(API_BASE + '/push/subscribe', {
        method:  'POST',
        headers: authHeaders(token),
        body:    JSON.stringify(body),
      });
      if (!res.ok) {
        console.warn('[Push] Server rejected subscription:', res.status);
        return false;
      }
      console.log('[Push] Subscribed and registered with server');
      return true;
    } catch (err) {
      console.error('[Push] Network error registering subscription:', err);
      return false;
    }
  }

  /**
   * Remove this device's subscription. Call on logout.
   */
  async function unsubscribePush(token) {
    try {
      if (!('serviceWorker' in navigator)) return;
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (!sub) return;

      if (token) {
        try {
          await fetch(API_BASE + '/push/subscribe', {
            method:  'DELETE',
            headers: authHeaders(token),
            body:    JSON.stringify({ endpoint: sub.endpoint }),
          });
        } catch (_) { /* offline is fine */ }
      }
      await sub.unsubscribe();
    } catch (err) {
      console.warn('[Push] unsubscribe failed:', err);
    }
  }

  // Expose as globals so the existing inline scripts in index.html can call them.
  window.initPushNotifications = initPushNotifications;
  window.unsubscribePush       = unsubscribePush;
})();