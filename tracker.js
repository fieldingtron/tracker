(function () {
  var endpoint = window.TINY_ANALYTICS_ENDPOINT || '/collect.php';
  var site = window.TINY_ANALYTICS_SITE || window.location.hostname;

  // Affiliate links whose pathname starts with this prefix are auto-tracked.
  // Override with: window.TINY_ANALYTICS_AFFILIATE_PATH = '/go/';
  var affiliatePath = window.TINY_ANALYTICS_AFFILIATE_PATH || '/go/';

  function sendEvent(payload) {
    payload = payload || {};
    payload.site = payload.site || site;
    payload.page_url = payload.page_url || window.location.href;
    payload.referrer = payload.referrer || document.referrer || '';
    payload.ts = new Date().toISOString();

    var body = JSON.stringify(payload);

    if (navigator.sendBeacon) {
      var blob = new Blob([body], { type: 'text/plain;charset=UTF-8' });
      navigator.sendBeacon(endpoint, blob);
      return;
    }

    if (window.fetch) {
      fetch(endpoint, {
        method: 'POST',
        body: body,
        keepalive: true,
        mode: 'cors',
        credentials: 'omit'
      }).catch(function () {});
    }
  }

  // Track page load.
  sendEvent({ event_type: 'pageview' });

  // Track all clicks via delegation.
  document.addEventListener(
    'click',
    function (event) {
      // Auto-track /go/* affiliate links — no HTML tagging required.
      var anchor = event.target.closest('a[href]');
      if (anchor) {
        try {
          var resolved = new URL(anchor.getAttribute('href'), window.location.href);
          if (resolved.pathname.indexOf(affiliatePath) === 0) {
            var slug = resolved.pathname.slice(affiliatePath.length).replace(/\/+$/, '') || 'affiliate';
            sendEvent({
              event_type: 'click',
              event_name: slug.slice(0, 255),
              event_value: anchor.getAttribute('href').slice(0, 2000)
            });
            return;
          }
        } catch (e) {}
      }

      // Track explicitly tagged elements.
      var tagged = event.target.closest('[data-analytics-click]');
      if (tagged) {
        var label = tagged.getAttribute('data-analytics-click') || tagged.textContent || 'click';
        var value = tagged.getAttribute('data-analytics-value') || tagged.getAttribute('href') || tagged.value || '';
        sendEvent({
          event_type: 'click',
          event_name: String(label).trim().slice(0, 255),
          event_value: String(value).slice(0, 2000)
        });
      }
    },
    true
  );

  // Optional API for custom events.
  window.TinyAnalytics = {
    track: function (name, value) {
      sendEvent({
        event_type: 'custom',
        event_name: String(name || 'custom').slice(0, 255),
        event_value: String(value || '').slice(0, 2000)
      });
    }
  };
})();
