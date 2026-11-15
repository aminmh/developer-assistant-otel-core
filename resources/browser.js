/*!
 * Indexr browser telemetry.
 *
 * Reports one trace per screen view: a `page` span with Web Vitals, child spans for slow
 * interactions and JavaScript errors, and a `traceparent` header on same-origin requests so the
 * back end's request spans join the same trace.
 *
 * Privacy: sends a route template, timings, and error class names. It never reads or sends a URL,
 * query string, element text, selector, error message, or user agent. Batches go to a path on the
 * page's own origin; the application's back end relays them.
 *
 * Configure before this script runs:
 *   window.__INDEXR_BROWSER__ = { endpoint: '/_indexer/browser', sampleRate: 0.1 };
 * Tell it which screen is showing (a route template, never a concrete path):
 *   window.indexrBrowser.setRoute('/orders/:id');
 * Inertia applications need no call: the page component name is used.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root && root.document && !root.indexrBrowser) root.indexrBrowser = api.install(root, root.__INDEXR_BROWSER__ || {});
})(typeof window !== 'undefined' ? window : undefined, function () {
  'use strict';

  var MAX_CHILD_SPANS = 10;          // slow interactions and errors kept per screen view
  var MAX_BATCH = 30;
  var SLOW_INTERACTION_MS = 200;
  var MAX_VIEW_MS = 30 * 60 * 1000;  // a tab left open is not a 9-hour page load
  var ROUTE = /^[A-Za-z0-9_\-./:{}*\[\]@ ]{1,200}$/;
  var ERROR_TYPE = /^[A-Za-z_$][A-Za-z0-9_$]{0,79}$/;
  var IDENTIFIER = /^[A-Za-z0-9_.\-]{1,128}$/;

  function hex(env, bytes) {
    var buffer = new Uint8Array(bytes);
    env.crypto.getRandomValues(buffer);
    var text = '';
    for (var i = 0; i < buffer.length; i++) text += (buffer[i] < 16 ? '0' : '') + buffer[i].toString(16);
    return text;
  }

  function install(env, options) {
    var config = {
      endpoint: typeof options.endpoint === 'string' ? options.endpoint : '/_indexer/browser',
      sampleRate: typeof options.sampleRate === 'number' ? Math.min(1, Math.max(0, options.sampleRate)) : 0.1,
      frontend: options.frontend && IDENTIFIER.test(options.frontend.projectId || '')
        ? { project_id: options.frontend.projectId, revision_id: String(options.frontend.revisionId || '') } : null,
    };
    var noop = { setRoute: function () {}, flush: function () {}, traceparent: function () { return null; }, sampled: false };
    var performance = env.performance;
    if (!env.crypto || !env.crypto.getRandomValues || !performance || !env.navigator || !env.location) return noop;
    // One decision per page load, so a screen's requests are either all traced or none.
    if (!(Math.random() < config.sampleRate)) return noop;

    var origin = env.location.origin;
    var now = function () { return Math.round(performance.timeOrigin + performance.now()); };
    var queue = [];
    var view = null;

    function startView(route, startMs, initial) {
      view = { trace: hex(env, 16), span: hex(env, 8), route: route, start: startMs, initial: initial,
               lcp: null, cls: 0, inp: 0, children: 0, sent: false };
    }

    function endView() {
      if (!view || view.sent) return;
      view.sent = true;
      var span = { type: 'page', trace_id: view.trace, span_id: view.span, route: view.route, start_ms: view.start,
                   duration_ms: Math.min(MAX_VIEW_MS, Math.max(0, now() - view.start)) };
      if (view.initial && view.lcp !== null) span.lcp_ms = Math.round(view.lcp);
      if (view.inp > 0) span.inp_ms = Math.round(view.inp);
      span.cls = Math.round(view.cls * 1000) / 1000;
      queue.push(span);
    }

    function child(type, startMs, durationMs, extra) {
      if (!view || view.sent || view.children >= MAX_CHILD_SPANS) return;
      view.children++;
      var span = { type: type, trace_id: view.trace, span_id: hex(env, 8), parent_span_id: view.span, route: view.route,
                   start_ms: Math.round(startMs), duration_ms: Math.max(0, Math.round(durationMs)) };
      for (var key in extra) span[key] = extra[key];
      queue.push(span);
    }

    function flush() {
      if (!queue.length) return;
      var batch = queue.splice(0, MAX_BATCH);
      queue.length = 0;
      var body = JSON.stringify({ v: 1, frontend: config.frontend, spans: batch });
      try {
        // text/plain keeps the request "simple", so no preflight; cookies ride along because it is same-origin.
        var sent = env.navigator.sendBeacon && env.navigator.sendBeacon(config.endpoint, new env.Blob([body], { type: 'text/plain' }));
        if (!sent && env.fetch) env.fetch(config.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin',
                                                                headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
      } catch (error) { /* telemetry must never break the page */ }
    }

    function setRoute(route) {
      route = typeof route === 'string' && ROUTE.test(route) ? route : '(unknown)';
      if (view && !view.sent && view.route === route) return;
      if (view && !view.sent && view.route === '(unknown)' && view.initial && view.children === 0) {
        view.route = route;           // the first screen learned its name after load: same view
        return;
      }
      endView();
      flush();
      startView(route, now(), false);
    }

    function traceparent() {
      return view && !view.sent ? '00-' + view.trace + '-' + view.span + '-01' : null;
    }

    function sameOrigin(url) {
      try {
        var target = new env.URL(String(url), env.location.href);
        return target.origin === origin && target.pathname !== config.endpoint;
      } catch (error) { return false; }
    }

    // Web Vitals and slow interactions.
    function observe(type, handler, extra) {
      try {
        var options = { type: type, buffered: true };
        for (var key in extra) options[key] = extra[key];
        new env.PerformanceObserver(function (list) { list.getEntries().forEach(handler); }).observe(options);
      } catch (error) { /* this browser does not report the entry type */ }
    }
    if (env.PerformanceObserver) {
      observe('largest-contentful-paint', function (entry) { if (view && view.initial && !view.sent) view.lcp = entry.startTime; });
      observe('layout-shift', function (entry) { if (view && !view.sent && !entry.hadRecentInput) view.cls += entry.value; });
      observe('event', function (entry) {
        if (!view || view.sent || !entry.interactionId) return;
        if (entry.duration > view.inp) view.inp = entry.duration;
        if (entry.duration >= SLOW_INTERACTION_MS) child('interaction', performance.timeOrigin + entry.startTime, entry.duration, {});
      }, { durationThreshold: 40 });
    }

    // JavaScript errors: the class name only, never the message.
    function errorType(value, fallback) {
      var name = value && typeof value === 'object' && typeof value.name === 'string' ? value.name : fallback;
      return ERROR_TYPE.test(name) ? name : fallback;
    }
    env.addEventListener('error', function (event) { child('error', now(), 0, { error_type: errorType(event && event.error, 'Error') }); });
    env.addEventListener('unhandledrejection', function (event) {
      child('error', now(), 0, { error_type: errorType(event && event.reason, 'UnhandledRejection') });
    });

    // Join the back end: add the trace context to same-origin requests only.
    if (typeof env.fetch === 'function') {
      var originalFetch = env.fetch;
      env.fetch = function (input, init) {
        var header = traceparent();
        try {
          var url = typeof input === 'string' || input instanceof env.URL ? input : input && input.url;
          if (header && url && sameOrigin(url)) {
            var headers = new env.Headers((init && init.headers) || (input && typeof input === 'object' && input.headers) || undefined);
            if (!headers.has('traceparent')) headers.set('traceparent', header);
            init = Object.assign({}, init, { headers: headers });
          }
        } catch (error) { /* fall through to the untouched request */ }
        return originalFetch.call(this, input, init);
      };
    }
    if (env.XMLHttpRequest) {
      var open = env.XMLHttpRequest.prototype.open;
      var send = env.XMLHttpRequest.prototype.send;
      env.XMLHttpRequest.prototype.open = function (method, url) {
        this.__indexrSameOrigin = sameOrigin(url);
        return open.apply(this, arguments);
      };
      env.XMLHttpRequest.prototype.send = function () {
        var header = traceparent();
        try { if (header && this.__indexrSameOrigin) this.setRequestHeader('traceparent', header); } catch (error) { /* request already sent */ }
        return send.apply(this, arguments);
      };
    }

    // Inertia names each screen by its page component, which is a template, not a URL.
    if (env.document) {
      env.document.addEventListener('inertia:navigate', function (event) {
        var page = event && event.detail && event.detail.page;
        if (page && typeof page.component === 'string') setRoute(page.component);
      });
      env.document.addEventListener('visibilitychange', function () {
        if (env.document.visibilityState === 'hidden') { endView(); flush(); }
        else if (view && view.sent) startView(view.route, now(), false);
      });
    }
    env.addEventListener('pagehide', function () { endView(); flush(); });

    startView('(unknown)', Math.round(performance.timeOrigin), true);
    return { setRoute: setRoute, flush: function () { endView(); flush(); }, traceparent: traceparent, sampled: true };
  }

  return { install: install };
});
