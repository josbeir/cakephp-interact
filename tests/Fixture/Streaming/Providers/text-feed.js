// webroot/js/text-feed.js; register before Interact's runtime starts.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('textFeed', () => {
    let feed;
    return {
      init() {
        const id = this.$el.closest('[data-interact-id]').dataset.interactId;
        feed = new window.EventSource(this.$el.dataset.feedUrl);
        feed.onmessage = (event) => {
          try {
            this.$server.receiveStream({
              version: 1,
              id,
              type: 'target',
              to: 'output',
              content: JSON.parse(event.data).text,
              replace: false,
              html: false,
            });
          } catch (error) {
            window.console.error('Rejected feed message', error);
          }
        };
      },
      destroy() {
        feed?.close();
      },
    };
  });
});
