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
      activity: '',
      receive(message) {
        if (message.event === 'message-sent')
          this.activity = 'Private message received in your tabs.';
      },
    }));
    window.Alpine.data('chatReply', () => ({
      active: false,
      progress: 0,
      receive(message) {
        if (message.event === 'reply-progress') {
          this.active = true;
          this.progress = message.detail.percent;
        }
      },
    }));
  };
  if (window.Alpine) register();
  else document.addEventListener('alpine:init', register, { once: true });
})();
