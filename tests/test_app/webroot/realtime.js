(() => {
  const register = () => {
    window.Alpine.data('chatMessages', () => {
      let observer;
      return {
        init() {
          const scroll = () => {
            this.$el.scrollTop = this.$el.scrollHeight;
          };
          this.$nextTick(scroll);
          observer = new window.MutationObserver(scroll);
          observer.observe(this.$el, {
            childList: true,
            subtree: true,
            characterData: true,
          });
        },
        destroy() {
          observer?.disconnect();
        },
      };
    });
    window.Alpine.data('chatDemo', () => ({
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
