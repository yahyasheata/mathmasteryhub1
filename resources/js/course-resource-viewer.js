(function () {
  'use strict';

  var viewer = document.querySelector('[data-resource-viewer]');
  if (!viewer || viewer.dataset.resourceViewerInitialized === 'true') return;
  viewer.dataset.resourceViewerInitialized = 'true';

  var stage = viewer.querySelector('[data-resource-viewer-stage]');
  var frame = viewer.querySelector('[data-resource-viewer-frame]');
  var loading = viewer.querySelector('[data-resource-viewer-loading]');
  var notice = viewer.querySelector('[data-resource-viewer-notice]');
  var status = viewer.querySelector('[data-resource-status]');
  var storageKey = viewer.getAttribute('data-resource-viewer-key') || '';
  var source = frame ? (frame.getAttribute('data-resource-viewer-src') || '') : '';
  var state = 'idle';
  var fallbackTimer = null;
  var loadSequence = 0;
  var debugEnabled = /(?:^|[?&])resourceViewerDebug=1(?:&|$)/.test(window.location.search);
  var diagnostics = [];

  function diagnostic(event, detail) {
    if (!debugEnabled) return;
    var entry = { time: Date.now(), event: event, detail: detail || '' };
    diagnostics.push(entry);
    window.__mmhResourceViewerDiagnostics = diagnostics;
    if (window.console && window.console.debug) window.console.debug('[resource-viewer]', entry);
  }

  function announce(message) {
    if (status) status.textContent = message;
  }

  function setNotice(message) {
    if (!notice) return;
    notice.textContent = message || '';
    notice.hidden = !message;
  }

  function setLoading(isLoading) {
    if (loading) loading.hidden = !isLoading;
    if (stage) stage.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    diagnostic(isLoading ? 'loading-visible' : 'loading-hidden');
  }

  function clearFallback() {
    if (fallbackTimer !== null) {
      window.clearTimeout(fallbackTimer);
      fallbackTimer = null;
    }
  }

  function setState(nextState, message) {
    state = nextState;
    viewer.setAttribute('data-resource-viewer-state', nextState);
    setLoading(nextState === 'loading');
    if (message) setNotice(message);
    if (nextState === 'ready') setNotice('');
    diagnostic('state:' + nextState, message || '');
  }

  function beginLoad(reason) {
    if (!frame || !stage || source === '') {
      setState('failed', 'This resource preview is unavailable. Please open it externally.');
      announce('Resource preview unavailable.');
      diagnostic('missing-frame-or-source');
      return;
    }

    clearFallback();
    loadSequence += 1;
    var sequence = loadSequence;
    setState('loading');
    announce(reason === 'reload' ? 'Reloading resource preview.' : 'Preparing resource preview.');
    diagnostic('load-start', reason || 'initial');

    // Listeners are attached before this assignment. The source is deliberately
    // assigned only after the state controller is armed, so cached Safari loads
    // cannot outrun it.
    try {
      frame.src = source;
      diagnostic('iframe-src-assigned', source);
    } catch (error) {
      diagnostic('iframe-src-exception', String(error));
      setState('failed', 'This resource preview could not be opened here. Please open it externally.');
      announce('Resource preview failed.');
      return;
    }

    fallbackTimer = window.setTimeout(function () {
      if (sequence !== loadSequence || state !== 'loading') return;
      // Drive and other cross-origin providers can leave a frame request pending
      // indefinitely. Never trap the student behind a blocking LMS overlay.
      setState('timed_out', 'The resource is taking longer than expected. You can keep waiting, reload, or open it externally.');
      announce('Resource preview is taking longer than expected.');
      diagnostic('fallback-timeout');
    }, 12000);
    diagnostic('fallback-started', '12000ms');
  }

  function resourcePreviewLoaded() {
    clearFallback();
    setState('ready');
    announce('Resource preview loaded.');
    diagnostic('iframe-load');
  }

  function resourcePreviewFailed() {
    clearFallback();
    setState('failed', 'This resource preview could not be loaded here. Please open it externally.');
    announce('Resource preview failed.');
    diagnostic('iframe-error');
  }

  function updateFullscreenControl() {
    var control = viewer.querySelector('[data-resource-fullscreen]');
    if (!control) return;
    var active = document.fullscreenElement === stage || document.webkitFullscreenElement === stage;
    control.setAttribute('aria-pressed', active ? 'true' : 'false');
    control.querySelector('span:last-child').textContent = active ? 'Exit fullscreen' : 'Fullscreen';
    var icon = control.querySelector('.fas');
    if (icon) icon.className = active ? 'fas fa-compress' : 'fas fa-expand';
  }

  try {
    diagnostic('script-initialized');
    diagnostic('viewer-found', String(Boolean(viewer)));
    diagnostic('iframe-found', String(Boolean(frame)));

    if (frame) {
      frame.addEventListener('load', resourcePreviewLoaded);
      frame.addEventListener('error', resourcePreviewFailed);
      diagnostic('iframe-listeners-attached');
      beginLoad('initial');
    } else {
      beginLoad('initial');
    }
  } catch (error) {
    diagnostic('initialization-exception', String(error));
    setState('failed', 'This resource preview could not be prepared. Please open it externally.');
    announce('Resource preview failed.');
  }

  var open = viewer.querySelector('[data-resource-open]');
  if (open && stage) {
    open.addEventListener('click', function () {
      var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      stage.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
      window.setTimeout(function () { stage.focus({ preventScroll: true }); }, reduceMotion ? 0 : 180);
      announce('Resource viewer focused.');
    });
  }

  var copy = viewer.querySelector('[data-resource-copy]');
  if (copy) {
    copy.addEventListener('click', function () {
      var done = function () { announce('Lesson link copied.'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(window.location.href).then(done).catch(function () {
          announce('Copy the lesson link from your browser address bar.');
        });
      } else {
        announce('Copy the lesson link from your browser address bar.');
      }
    });
  }

  var reload = viewer.querySelector('[data-resource-reload]');
  if (reload) {
    reload.addEventListener('click', function () {
      diagnostic('reload-requested');
      beginLoad('reload');
    });
  }

  var fullscreen = viewer.querySelector('[data-resource-fullscreen]');
  if (fullscreen && stage) {
    fullscreen.addEventListener('click', function () {
      diagnostic('fullscreen-requested');
      var active = document.fullscreenElement === stage || document.webkitFullscreenElement === stage;
      if (active) {
        var exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (exit) exit.call(document);
        return;
      }
      var request = stage.requestFullscreen || stage.webkitRequestFullscreen || stage.msRequestFullscreen;
      if (request) request.call(stage);
    });
    document.addEventListener('fullscreenchange', updateFullscreenControl);
    document.addEventListener('webkitfullscreenchange', updateFullscreenControl);
  }

  if (debugEnabled) {
    window.addEventListener('error', function (event) {
      diagnostic('window-error', event.message || 'Unknown error');
    });
  }

  // Provider-owned frames intentionally keep their own page and zoom controls.
  // Persist only the LMS page position; cross-origin iframe internals are never
  // read or modified by the parent page.
  if (storageKey) {
    try {
      var saved = Number(localStorage.getItem(storageKey));
      if (Number.isFinite(saved) && saved > 0 && !window.location.hash) {
        window.requestAnimationFrame(function () { window.scrollTo(0, saved); });
      }
      var pending = false;
      window.addEventListener('scroll', function () {
        if (pending) return;
        pending = true;
        window.requestAnimationFrame(function () {
          localStorage.setItem(storageKey, String(window.scrollY || 0));
          pending = false;
        });
      }, { passive: true });
    } catch (error) {}
  }
}());
