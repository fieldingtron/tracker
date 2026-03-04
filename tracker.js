(function () {
  var endpoint = window.TINY_ANALYTICS_ENDPOINT || '/collect.php';
  var site = window.TINY_ANALYTICS_SITE || window.location.hostname;

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

  // Track clicks on explicitly tagged elements.
  document.addEventListener(
    'click',
    function (event) {
      var target = event.target.closest('[data-analytics-click]');
      if (!target) {
        return;
      }

      var name = target.getAttribute('data-analytics-click') || target.textContent || 'click';
      var value =
        target.getAttribute('data-analytics-value') ||
        target.getAttribute('href') ||
        target.value ||
        '';

      sendEvent({
        event_type: 'click',
        event_name: String(name).trim().slice(0, 255),
        event_value: String(value).slice(0, 2000)
      });
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
