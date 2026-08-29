(function () {
  'use strict';
  var panel = document.querySelector('[data-homework-upload-panel]');
  var open = document.querySelector('[data-homework-upload]');
  var cancel = document.querySelector('[data-homework-upload-cancel]');
  var form = document.querySelector('[data-homework-submission]');
  var message = document.querySelector('[data-homework-upload-message]');
  var input = form ? form.querySelector('input[type="file"]') : null;
  var fileList = form ? form.querySelector('[data-homework-file-list]') : null;
  var selectedFiles = [];
  var maxFiles = form ? parseInt(form.getAttribute('data-max-files') || '10', 10) : 10;

  function showPanel(show) {
    if (!panel) return;
    panel.hidden = !show;
    if (show && input) input.focus();
  }
  function setMessage(text) { if (message) message.textContent = text; }
  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }
  function syncInput() {
    if (!input) return;
    if (typeof DataTransfer !== 'undefined') {
      var transfer = new DataTransfer();
      selectedFiles.forEach(function (file) { transfer.items.add(file); });
      input.files = transfer.files;
    }
  }
  function renderFiles() {
    if (!fileList) return;
    fileList.textContent = '';
    if (!selectedFiles.length) { fileList.hidden = true; return; }
    fileList.hidden = false;
    var heading = document.createElement('p');
    heading.className = 'course-homework-file-count';
    heading.textContent = selectedFiles.length + ' file' + (selectedFiles.length === 1 ? '' : 's') + ' selected';
    fileList.appendChild(heading);
    selectedFiles.forEach(function (file, index) {
      var row = document.createElement('div');
      row.className = 'course-homework-file-row';
      var name = document.createElement('span');
      name.className = 'course-homework-file-name';
      name.textContent = file.name;
      name.title = file.name;
      var size = document.createElement('span');
      size.className = 'course-homework-file-size';
      size.textContent = formatSize(file.size);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'course-btn course-btn-ghost course-homework-file-remove';
      remove.textContent = 'Remove';
      remove.addEventListener('click', function () {
        selectedFiles.splice(index, 1);
        syncInput();
        renderFiles();
      });
      row.appendChild(name); row.appendChild(size); row.appendChild(remove);
      fileList.appendChild(row);
    });
  }
  function addFiles(files) {
    var incoming = Array.prototype.slice.call(files || []);
    if (selectedFiles.length + incoming.length > maxFiles) {
      setMessage('You can submit up to ' + maxFiles + ' files in one Homework submission.');
      incoming = incoming.slice(0, Math.max(0, maxFiles - selectedFiles.length));
    }
    incoming.forEach(function (file) {
      var duplicate = selectedFiles.some(function (existing) { return existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified; });
      if (!duplicate) selectedFiles.push(file);
    });
    syncInput();
    renderFiles();
    if (selectedFiles.length) setMessage('All selected files will be sent as one Homework submission.');
  }
  function submissionEndpoint() {
    var action = (form.getAttribute('action') || '').trim();
    if (!action) throw new Error('The submission address is unavailable. Please refresh and try again.');
    return new URL(action, window.location.href).toString();
  }
  function submissionFormData() {
    var payload = new FormData(form);
    // selectedFiles is canonical while the native picker is reused for incremental selection.
    // Replace any browser-provided file entries so each selected file is sent exactly once.
    payload.delete('submission_files[]');
    selectedFiles.forEach(function (file) { payload.append('submission_files[]', file, file.name); });
    return payload;
  }
  function submissionResponse(response) {
    return response.text().then(function (body) {
      var result;
      try { result = body ? JSON.parse(body) : null; } catch (error) { throw new Error('The submission service returned an invalid response. Please refresh and try again.'); }
      if (!response.ok || !result || !result.success) throw new Error((result && result.message) || 'Unable to submit homework.');
      return result;
    });
  }
  if (open) open.addEventListener('click', function () { showPanel(true); });
  if (cancel) cancel.addEventListener('click', function () { showPanel(false); });
  if (input) input.addEventListener('change', function () {
    addFiles(input.files);
    if (typeof DataTransfer !== 'undefined') input.value = '';
  });
  if (!form) return;
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (form.dataset.homeworkSubmitting === '1') return;
    if (!selectedFiles.length) { setMessage('Choose at least one answer file before submitting.'); return; }
    var submit = form.querySelector('button[type="submit"]');
    var endpoint;
    try { endpoint = submissionEndpoint(); } catch (error) { setMessage(error.message); return; }
    syncInput();
    form.dataset.homeworkSubmitting = '1';
    if (submit) submit.disabled = true;
    setMessage('Submitting homework…');
    fetch(endpoint, { method: 'POST', body: submissionFormData(), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(submissionResponse)
      .then(function (result) { setMessage(result.message || 'Homework submitted.'); window.setTimeout(function () { window.location.reload(); }, 500); })
      .catch(function (error) { setMessage(error.message || 'Unable to submit homework.'); delete form.dataset.homeworkSubmitting; if (submit) submit.disabled = false; });
  });
}());
