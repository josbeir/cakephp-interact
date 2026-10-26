// Application-owned protocol: subscribe with a token, receive an acknowledgement,
// then receive normalized Interact PushMessage envelopes from the authorized server.
document.addEventListener('interact:configure', (event) => {
  event.detail.registerTransport('websocket', (grant, callbacks) => {
    const url = new window.URL(grant.data.url);
    if (!['ws:', 'wss:'].includes(url.protocol)) {
      throw new Error('Invalid WebSocket provider URL.');
    }
    let socket;
    let cleanup;
    let timer;
    let stopped = false;
    let failures = 0;

    const connect = () => {
      if (stopped) return;
      socket = new window.WebSocket(url);
      const current = socket;
      const listeners = new window.AbortController();
      cleanup = () => {
        listeners.abort();
        current.close();
      };
      let authorized = false;
      current.addEventListener(
        'open',
        () => {
          current.send(
            JSON.stringify({
              type: 'subscribe',
              token: grant.data.token,
              topics: grant.data.topics,
            }),
          );
        },
        { signal: listeners.signal },
      );
      current.addEventListener(
        'message',
        (message) => {
          if (stopped || current !== socket) return;
          try {
            const value = JSON.parse(message.data);
            if (value.type === 'subscribed') {
              authorized = true;
              failures = 0;
              callbacks.status('connected');
            } else if (authorized) {
              callbacks.receive(value);
            } else {
              throw new Error('Subscription acknowledgement required.');
            }
          } catch {
            stopped = true;
            callbacks.status('error', 'WebSocket message rejected.');
            cleanup();
          }
        },
        { signal: listeners.signal },
      );
      current.addEventListener(
        'close',
        () => {
          listeners.abort();
          if (stopped) return;
          callbacks.status('reconnecting');
          timer = window.setTimeout(
            connect,
            Math.min(30000, 500 * 2 ** Math.min(failures++, 6)) *
              (0.75 + Math.random() * 0.5),
          );
        },
        { signal: listeners.signal },
      );
    };

    connect();
    return () => {
      stopped = true;
      window.clearTimeout(timer);
      cleanup?.();
    };
  });
});
