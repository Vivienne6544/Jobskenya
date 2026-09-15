self.addEventListener('push', function(e){
  let data = {};
  try { data = e.data ? e.data.json() : {}; } catch(err){}
  
  const title = data.title || 'Jobske';
  const options = {
    body: data.body || 'You have a new update',
    icon: '/Jobskenewversion/icon.png',
    data: { url: data.url || '/Jobskenewversion/home.php' }
  };
  e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', e=>{
  e.notification.close();
  e.waitUntil(clients.openWindow(e.notification.data.url));
});