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

  function trackLabeledClick(target, eventType, labelAttr) {
    var label = target.getAttribute(labelAttr) || target.textContent || eventType;
    var value =
      target.getAttribute('data-analytics-value') ||
      target.getAttribute('href') ||
      target.value ||
      '';

    sendEvent({
      event_type: eventType,
      event_name: String(label).trim().slice(0, 255),
      event_value: String(value).slice(0, 2000)
    });
  }

  // Track page load.
  sendEvent({ event_type: 'pageview' });

  // Track clicks on explicitly tagged elements.
  document.addEventListener(
    'click',
    function (event) {
      var target = event.target.closest('[data-analytics-affiliate], [data-analytics-click]');
      if (!target) {
        return;
      }

      if (target.hasAttribute('data-analytics-affiliate')) {
        trackLabeledClick(target, 'affiliate_click', 'data-analytics-affiliate');
        return;
      }

      trackLabeledClick(target, 'click', 'data-analytics-click');
    },
    true
  );

  // Optional API for custom events.
  window.TinyAnalytics = {
    redirectUrl: function (targetUrl, name) {
      var endpointUrl = new URL(endpoint, window.location.href);
      var redirect = new URL((window.TINY_ANALYTICS_REDIRECT || '/redirect.php'), endpointUrl);
      redirect.searchParams.set('to', String(targetUrl || ''));
      redirect.searchParams.set('name', String(name || 'redirect').slice(0, 255));
      redirect.searchParams.set('site', site);
      return redirect.toString();
    },
    trackAffiliate: function (name, url) {
      sendEvent({
        event_type: 'affiliate_click',
        event_name: String(name || 'affiliate').slice(0, 255),
        event_value: String(url || '').slice(0, 2000)
      });
    },
    track: function (name, value) {
      sendEvent({
        event_type: 'custom',
        event_name: String(name || 'custom').slice(0, 255),
        event_value: String(value || '').slice(0, 2000)
      });
    }
  };
})();
