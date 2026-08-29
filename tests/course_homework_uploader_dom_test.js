const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

class Element {
  constructor(attrs = {}) {
    this.attrs = attrs;
    this.listeners = {};
    this.children = [];
    this.hidden = false;
    this.dataset = {};
    this.files = [];
    this.required = false;
    this.disabled = false;
    this.value = '';
    this._text = '';
    Object.defineProperty(this, 'textContent', { get: () => this._text, set: (value) => { this._text = String(value); if (this._text === '') this.children = []; } });
  }
  addEventListener(type, handler) { this.listeners[type] = handler; }
  dispatch(type, event = {}) { if (this.listeners[type]) this.listeners[type](event); }
  appendChild(child) { this.children.push(child); return child; }
  focus() {}
  getAttribute(name) { return this.attrs[name] || ''; }
  querySelector(selector) {
    if (selector === 'input[type="file"]') return this.fileInput;
    if (selector === '[data-homework-file-list]') return this.fileList;
    if (selector === 'button[type="submit"]') return this.submit;
    return null;
  }
}

const panel = new Element();
const open = new Element();
const cancel = new Element();
const message = new Element();
const input = new Element();
const fileList = new Element();
const submit = new Element();
const form = new Element({ action: '/user/requests/assignment/submission', 'data-max-files': '10' });
form.fileInput = input;
form.fileList = fileList;
form.submit = submit;

const document = {
  createElement() { return new Element(); },
  querySelector(selector) {
    return {
      '[data-homework-upload-panel]': panel,
      '[data-homework-upload]': open,
      '[data-homework-upload-cancel]': cancel,
      '[data-homework-submission]': form,
      '[data-homework-upload-message]': message,
    }[selector] || null;
  }
};

let requestBody = null;
let fetchCount = 0;
let reloaded = false;
class TestFormData {
  constructor(source) {
    this.entries = [];
    if (source === form && input.files.length) this.entries.push(['submission_files[]', input.files[0]]);
  }
  delete(name) { this.entries = this.entries.filter(([key]) => key !== name); }
  append(name, value, filename) { this.entries.push([name, value, filename]); }
}

const context = {
  document,
  FormData: TestFormData,
  URL,
  window: { location: { href: 'https://example.test/user/course/1', reload: () => { reloaded = true; } }, setTimeout: (fn) => fn() },
  fetch: (_url, options) => {
    fetchCount += 1;
    requestBody = options.body;
    return Promise.resolve({ ok: true, text: () => Promise.resolve(JSON.stringify({ success: true, message: 'Homework submitted.' })) });
  },
  console,
};
vm.runInNewContext(fs.readFileSync('resources/js/course-homework.js', 'utf8'), context);

const fileA = { name: 'a.pdf', size: 100, lastModified: 1 };
const fileB = { name: 'b.pdf', size: 200, lastModified: 2 };
input.files = [fileA];
input.dispatch('change');
assert.strictEqual(input.required, false, 'native required must remain disabled for the custom uploader');
assert.strictEqual(fileList.hidden, false, 'selected-file list should be visible');
assert.match(fileList.children[0].textContent, /1 file selected/);

input.files = [fileB];
input.dispatch('change');
assert.match(fileList.children[0].textContent, /2 files selected/);

const firstRowRemove = fileList.children[1].children[2];
firstRowRemove.dispatch('click');
assert.match(fileList.children[0].textContent, /1 file selected/);

form.dispatch('submit', { preventDefault() {} });
(async () => {
  await new Promise((resolve) => setImmediate(resolve));
  assert.strictEqual(fetchCount, 1, 'one submission request should be sent');
  const files = requestBody.entries.filter(([key]) => key === 'submission_files[]').map(([, value]) => value.name);
  assert.deepStrictEqual(files, ['b.pdf'], 'request must contain exactly the visible remaining file');

  const remainingRemove = fileList.children[1].children[2];
  remainingRemove.dispatch('click');
  delete form.dataset.homeworkSubmitting;
  form.dispatch('submit', { preventDefault() {} });
  assert.match(message.textContent, /Choose at least one answer file/);
  assert.strictEqual(fetchCount, 1, 'empty selection must not submit');
  console.log('course_homework_uploader_dom=PASS one_file_validation=PASS exact_formdata=PASS remove=PASS empty_block=PASS');
})().catch((error) => { console.error(error); process.exitCode = 1; });
