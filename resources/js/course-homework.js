(function () {
  'use strict';
  var panel = document.querySelector('[data-homework-upload-panel]');
  var open = document.querySelector('[data-homework-upload]');
  var cancel = document.querySelector('[data-homework-upload-cancel]');
  var form = document.querySelector('[data-homework-submission]');
  var message = document.querySelector('[data-homework-upload-message]');

  function showPanel(show) {
    if (!panel) return;
    panel.hidden = !show;
    if (show) {
      var input = panel.querySelector('input[type="file"]');
      if (input) input.focus();
    }
  }

  function setMessage(text) {
    if (message) message.textContent = text;
  }

  function submissionEndpoint() {
    var action = (form.getAttribute('action') || '').trim();
    if (!action) throw new Error('The submission address is unavailable. Please refresh and try again.');
    try {
      return new URL(action, window.location.href).toString();
    } catch (error) {
      throw new Error('The submission address is invalid. Please refresh and try again.');
    }
  }

  function submissionResponse(response) {
    return response.text().then(function (body) {
      var result;
      try {
        result = body ? JSON.parse(body) : null;
      } catch (error) {
        throw new Error('The submission service returned an invalid response. Please refresh and try again.');
      }
      if (!response.ok || !result || !result.success) {
        throw new Error((result && result.message) || 'Unable to submit homework.');
      }
      return result;
    });
  }

  if (open) open.addEventListener('click', function () { showPanel(true); });
  if (cancel) cancel.addEventListener('click', function () { showPanel(false); });
  if (!form) return;

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (form.dataset.homeworkSubmitting === '1') return;

    var submit = form.querySelector('button[type="submit"]');
    var endpoint;
    try {
      endpoint = submissionEndpoint();
    } catch (error) {
      setMessage(error.message || 'Unable to submit homework.');
      return;
    }

    form.dataset.homeworkSubmitting = '1';
    if (submit) submit.disabled = true;
    setMessage('Submitting homework…');

    fetch(endpoint, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(submissionResponse)
      .then(function (result) {
        setMessage(result.message || 'Homework submitted.');
        window.setTimeout(function () { window.location.reload(); }, 500);
      })
      .catch(function (error) {
        setMessage(error.message || 'Unable to submit homework.');
        delete form.dataset.homeworkSubmitting;
        if (submit) submit.disabled = false;
      });
  });
}());
