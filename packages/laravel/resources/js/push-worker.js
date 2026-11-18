self.addEventListener('push', event => {
  let payload;
  try {
    payload = event.data?.json();
  } catch {
    payload = null;
  }
  const workspace = /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(payload?.workspace ?? '') ? payload.workspace : null;
  const path = typeof payload?.path === 'string' && /^\/(?!\/)[a-z0-9/_.~%-]*$/i.test(payload.path) ? payload.path : '/';
  const url = new URL(path, self.location.origin);
  url.searchParams.set('fmsView', 'notifications');
  if (workspace) url.searchParams.set('fmsWorkspace', workspace);

  event.waitUntil(self.registration.showNotification('FLEX Media Studio', {
    body: typeof payload?.body === 'string' ? payload.body : 'You have a new Studio notification.',
    tag: 'fms-inbox-' + (workspace ?? 'default'),
    data: url.href,
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const url = new URL(event.notification.data, self.location.origin);
    if (url.origin !== self.location.origin) return;
    const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
    const exact = windows.find(client => client.url === url.href);
    if (exact) return exact.focus();
    const studio = windows.find(client => {
      const current = new URL(client.url);
      return current.origin === url.origin && current.pathname === url.pathname && current.searchParams.has('fmsWorkspace');
    });
    if (studio) {
      const navigated = await studio.navigate(url.href);
      if (navigated) return navigated.focus();
    }
    return self.clients.openWindow(url.href);
  })());
});
