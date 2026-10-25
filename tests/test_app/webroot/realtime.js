(() => {
  const register = () => {
    window.Alpine.data('publishingDesk', () => ({
      notification: '',
      notificationCount: 0,
      progress: 0,
      receive(message) {
        if (message.event === 'notification') {
          this.notification = message.detail.text;
          this.notificationCount++;
        }
        if (message.event === 'report-progress') {
          this.progress = message.detail.percent;
        }
      },
    }));
  };
  if (window.Alpine) register();
  else document.addEventListener('alpine:init', register, { once: true });
})();
