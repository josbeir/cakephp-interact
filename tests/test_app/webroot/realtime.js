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
  };
  if (window.Alpine) register();
  else document.addEventListener('alpine:init', register, { once: true });
})();
