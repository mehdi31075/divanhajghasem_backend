import {TokenApi, ApiError, escapeHtml as e, imageUrl, matchingWrite} from './client.js';
let storage;
try { storage = window.sessionStorage; } catch { storage = null; }
const api = new TokenApi(new URL(document.querySelector('meta[name=divan-api]')?.content || '/mobile-api.php', document.baseURI), {storage});
const $ = selector => document.querySelector(selector);
const state = {categories: [], dirty: false, pending: null, route: '', generation: 0, editor: null, user: ''};
const icon = name => `<svg aria-hidden="true"><use href="#i-${name}"/></svg>`;
const digits = value => new Intl.NumberFormat('fa').format(value);
const button = (label, route) => `<button type="button" data-route="${e(route)}">${e(label)}</button>`;
function notice(message = '', error = false) { $('#notice').textContent = message; $('#notice').className = error ? 'error' : 'success'; }
function formMessage(form, message, error = true) {
  const box = form.querySelector('.form-message'); box.textContent = message; box.className = `form-message ${error ? 'error' : 'success'}`;
}
function displayDate(value) {
  if (!value) return 'ثبت نشده';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'ثبت نشده' : new Intl.DateTimeFormat('fa-IR', {dateStyle: 'medium', timeStyle: 'short'}).format(date);
}
function lock(message) {
  api.setToken(''); $('#panel').hidden = true; $('#login').hidden = false;
  $('#login-message').textContent = message; $('#login-message').className = 'error';
}
function showPanel(user) {
  document.querySelector('.sidebar-foot')?.classList.add('mobile-visible');
  state.user = user; $('#identity').textContent = user; $('#panel').hidden = false; $('#login').hidden = true;
}
function disposeEditor() { if (state.editor) { state.editor.destroy(); state.editor = null; } }
function preview(frame, html) {
  // No scripts, forms, top-level navigation or parent-origin access in previews.
  frame.srcdoc = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>body{font:22px/2 Tahoma,sans-serif;color:#172b28;padding:20px}img{max-width:100%;height:auto}</style></head><body>' + html + '</body></html>';
}
async function categories() { state.categories = await api.call(''); return state.categories; }
function categoryOptions(selected, all = false) {
  return (all ? '<option value="">همه دسته‌ها</option>' : '') + state.categories.map(c => `<option value="${e(c.cid)}" ${String(c.cid) === String(selected) ? 'selected' : ''}>${e(c.category_name)}</option>`).join('');
}
function editorHtml(original) {
  return state.editor && state.editor.checkDirty() ? state.editor.getData() :
    state.editor ? original : $('#body').value;
}
function markDirty() { state.dirty = true; }
function sameSiteMediaUrl(value) {
  const url = new URL(value, api.endpoint);
  if (!['http:', 'https:'].includes(url.protocol) || url.origin !== api.endpoint.origin) throw new ApiError('آدرس فایل روی هاست دیوان معتبر نیست.');
  return url.href;
}
function insertVideo(editor, url) {
  const safe = sameSiteMediaUrl(url).replaceAll('&', '&amp;').replaceAll('"', '&quot;');
  const html = `<figure class="media"><video controls playsinline preload="metadata" src="${safe}"></video></figure><p></p>`;
  const view = editor.data.processor.toView(html);
  const model = editor.data.toModel(view);
  editor.model.insertContent(model, editor.model.document.selection);
  editor.editing.view.focus();
}
function insertImage(editor, url, name = '') {
  const safe = sameSiteMediaUrl(url).replaceAll('&', '&amp;').replaceAll('"', '&quot;');
  const safeAlt = (name || '').replaceAll('&', '&amp;').replaceAll('"', '&quot;');
  const html = `<figure class="image"><img src="${safe}" alt="${safeAlt}" loading="lazy"></figure><p></p>`;
  const view = editor.data.processor.toView(html);
  const model = editor.data.toModel(view);
  editor.model.insertContent(model, editor.model.document.selection);
  editor.editing.view.focus();
}
function mediaLibrary(editor = null, initialFilter = 'all') {
  const dialog = document.createElement('dialog');
  dialog.className = 'media-dialog'; dialog.setAttribute('dir', 'rtl');
  let currentFilter = initialFilter;
  const title = initialFilter === 'video' ? 'کتابخانهٔ ویدیو' : initialFilter === 'image' ? 'کتابخانهٔ تصاویر' : 'کتابخانهٔ رسانه';
  const acceptMap = {
    all: 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm',
    image: 'image/jpeg,image/png,image/gif,image/webp',
    video: 'video/mp4,video/webm,.mp4,.webm',
  };
  dialog.innerHTML = `<form method="dialog"><div class="media-dialog-head"><h2>${title}</h2><button type="submit" aria-label="بستن">بستن</button></div></form><div class="media-tabs" role="tablist"><button type="button" class="tab-btn ${currentFilter === 'all' ? 'active' : ''}" data-media-tab="all">همهٔ رسانه‌ها</button><button type="button" class="tab-btn ${currentFilter === 'image' ? 'active' : ''}" data-media-tab="image">تصاویر</button><button type="button" class="tab-btn ${currentFilter === 'video' ? 'active' : ''}" data-media-tab="video">ویدیوها</button></div><label>بارگذاری فایل تازه<input id="media-file" type="file" accept="${acceptMap[currentFilter] || acceptMap.all}"></label><p class="help">تصاویر تا ۱۰ مگابایت و MP4/WebM تا ۵۰ مگابایت. تغییر نام، آدرس فایل‌های درج‌شده در نوشته‌ها را عوض نمی‌کند. حذف فایل ممکن است رسانهٔ درج‌شده در نوشته را از دسترس خارج کند.</p><div class="media-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div><p class="media-message" role="status" aria-live="polite"></p><div class="media-list"></div>`;
  document.body.append(dialog);
  const message = dialog.querySelector('.media-message'); const list = dialog.querySelector('.media-list');
  const progress = dialog.querySelector('.media-progress'); const progressBar = progress.querySelector('progress'); const progressText = progress.querySelector('span');
  const fileInput = dialog.querySelector('#media-file');
  let cachedEntries = [];
  dialog.addEventListener('close', () => dialog.remove(), {once: true});
  const render = entries => {
    cachedEntries = entries;
    const items = currentFilter === 'all' ? entries : entries.filter(item => item.type === currentFilter);
    list.innerHTML = items.map(item => {
      let src; try { src = sameSiteMediaUrl(item.url); } catch { return ''; }
      const id = decodeURIComponent(new URL(src).pathname.split('/').pop());
      const preview = item.type === 'video' ? `<video controls preload="metadata" src="${e(src)}"></video>` : `<img src="${e(src)}" alt="${e(item.name)}" loading="lazy">`;
      let insertBtn = '';
      if (editor) {
        if (item.type === 'video') {
          insertBtn = `<button type="button" class="primary compact" data-insert-video="${e(src)}">درج ویدیو در متن</button>`;
        } else {
          insertBtn = `<button type="button" class="primary compact" data-insert-image="${e(src)}" data-image-name="${e(item.name)}">درج تصویر در متن</button>`;
        }
      }
      return `<article class="media-item" data-media-row="${e(id)}">${preview}<div><strong>${e(item.name)}</strong><small>${item.type === 'video' ? 'ویدیو' : 'تصویر'} · ${e(item.created_at || '')} · ${digits(Math.ceil((item.size_bytes || 0) / 1024))} کیلوبایت</small><div class="media-actions">${insertBtn}<button type="button" data-rename-media="${e(id)}">ویرایش نام</button><button type="button" class="danger" data-delete-media="${e(id)}">حذف فایل</button></div></div></article>`;
    }).join('') || `<p class="empty">هنوز ${currentFilter === 'video' ? 'ویدیویی' : currentFilter === 'image' ? 'تصویری' : 'رسانه‌ای'} بارگذاری نشده است.</p>`;
  };
  dialog.querySelectorAll('[data-media-tab]').forEach(tab => {
    tab.addEventListener('click', () => {
      currentFilter = tab.dataset.mediaTab;
      dialog.querySelectorAll('[data-media-tab]').forEach(t => t.classList.toggle('active', t === tab));
      fileInput.accept = acceptMap[currentFilter] || acceptMap.all;
      render(cachedEntries);
    });
  });
  const refresh = async () => {
    message.textContent = 'در حال دریافت فایل‌های هاست…';
    try { const result = await api.call('media_list'); render(result.media || result.videos || []); message.textContent = ''; }
    catch (error) { message.textContent = error.message; }
  };
  list.addEventListener('click', event => {
    const imgBtn = event.target.closest('[data-insert-image]');
    if (imgBtn) {
      try { insertImage(editor, imgBtn.dataset.insertImage, imgBtn.dataset.imageName); markDirty(); dialog.close(); }
      catch (error) { message.textContent = error.message; }
      return;
    }
    const vidBtn = event.target.closest('[data-insert-video]');
    if (vidBtn) {
      try { insertVideo(editor, vidBtn.dataset.insertVideo); markDirty(); dialog.close(); }
      catch (error) { message.textContent = error.message; }
    }
  });
  list.addEventListener('click', async event => {
    const rename = event.target.closest('[data-rename-media]');
    if (rename) {
      const row = rename.closest('[data-media-row]'); const current = row.querySelector('strong').textContent;
      const name = window.prompt('نام نمایشی رسانه', current); if (name === null || !name.trim()) return;
      rename.disabled = true;
      try { await api.call('media_update', {method: 'POST', fields: {id: rename.dataset.renameMedia, name: name.trim()}}); await refresh(); }
      catch (error) { message.textContent = error.uncertain ? 'نتیجهٔ تغییر نام مشخص نیست؛ فهرست را بررسی می‌کنم.' : error.message; await refresh(); }
      finally { rename.disabled = false; }
      return;
    }
    const remove = event.target.closest('[data-delete-media]');
    if (remove) {
      if (!window.confirm('این فایل برای همیشه از هاست حذف شود؟ اگر در نوشته‌ای استفاده شده باشد، دیگر نمایش داده نمی‌شود.')) return;
      remove.disabled = true;
      try { await api.call('media_delete', {method: 'POST', fields: {id: remove.dataset.deleteMedia}}); message.textContent = 'فایل حذف شد.'; await refresh(); }
      catch (error) { message.textContent = error.uncertain ? 'نتیجهٔ حذف مشخص نیست؛ فهرست را بررسی می‌کنم.' : error.message; await refresh(); remove.disabled = false; }
    }
  });
  dialog.querySelector('#media-file').addEventListener('change', async event => {
    const file = event.target.files?.[0]; if (!file) return;
    const input = event.target; input.disabled = true; message.textContent = `در حال بارگذاری ${file.type.startsWith('video/') ? 'ویدیو' : 'تصویر'}…`; progress.hidden = false; progressBar.value = 0; progressText.textContent = '۰٪';
    try {
      const fields = new FormData(); fields.append('media_type', file.type.startsWith('video/') ? 'video' : 'image'); fields.append('media_file', file);
      await api.upload('media_upload', {fields, onProgress: value => { if (value !== null) { progressBar.value = value; progressText.textContent = `${digits(value)}٪`; } }});
      message.textContent = 'فایل در هاست ذخیره شد.';
      await refresh();
    } catch (error) {
      message.textContent = error.uncertain ? 'نتیجهٔ بارگذاری قطعی نیست؛ فهرست را بررسی کنید و فایل را دوباره نفرستید.' : error.message;
      if (error.uncertain) await refresh();
    } finally { input.disabled = false; input.value = ''; setTimeout(() => { progress.hidden = true; }, 1200); }
  });
  dialog.showModal(); void refresh();
  return () => dialog.remove();
}
function replaceToolbarIcons(editor) {
  const labels = [
    [/redo|باز ?انجام/, 'دوباره'], [/undo|بازگردانی/, 'واگرد'],
    [/image.*url|url.*image|تصویر.*نشانی|نشانی.*تصویر/, 'تصویر از نشانی'],
    [/upload image|computer|بارگذار.*تصویر|آپلود.*تصویر/, 'بارگذاری تصویر'],
    [/link|پیوند/, 'پیوند'], [/table|جدول/, 'جدول'], [/horizontal line|خط افقی/, 'خط جداکننده'],
    [/special character|کاراکتر ویژه/, 'نویسهٔ ویژه'], [/source|سورس/, 'کد HTML'],
    [/fullscreen|full screen|تمام صفحه/, 'تمام‌صفحه'], [/heading|متن معمولی|عنوان/, 'سبک متن'],
    [/font family|family font|خانواده فونت/, 'قلم'], [/font size|اندازه فونت/, 'اندازه'],
    [/strikethrough|خط خورده/, 'خط‌خورده'], [/remove format|حذف کردن قالب/, 'پاک‌کردن قالب'],
    [/bulleted list|نشانه.?دار/, 'فهرست'], [/numbered list|لیست عددی/, 'فهرست عددی'],
    [/outdent|کاهش تورفتگی/, 'کاهش تورفتگی'], [/indent|افزایش تورفتگی/, 'افزایش تورفتگی'],
    [/block.?quote|نقل قول/, 'نقل‌قول'], [/alignment|تراز متن/, 'چینش'],
    [/font.?color|رنگ فونت/, 'رنگ متن'], [/background|پس.?زمینه/, 'رنگ زمینه'],
    [/bold|درشت/, 'درشت'], [/italic|کج/, 'کج'], [/underline|خط زیر/, 'زیرخط'],
  ];
  for (const button of editor.ui.view.toolbar.element.querySelectorAll('button')) {
    const current = [button.getAttribute('aria-label'), button.getAttribute('title'), button.getAttribute('data-cke-tooltip-text'), button.textContent]
      .filter(Boolean).join(' ').toLowerCase();
    const match = labels.find(([pattern]) => pattern.test(current));
    if (match) { button.setAttribute('aria-label', match[1]); button.title = match[1]; }
  }
}
class ImageUploadAdapter {
  constructor(loader) { this.loader = loader; this.aborted = false; }
  upload() {
    return this.loader.file.then(file => {
      if (this.aborted) throw new Error('بارگذاری لغو شد.');
      const fields = new FormData(); fields.append('media_type', 'image'); fields.append('media_file', file);
      return api.call('media_upload', {method: 'POST', fields}).then(result => {
        if (this.aborted) throw new Error('بارگذاری لغو شد.');
        return {default: sameSiteMediaUrl(result.media.url)};
      });
    });
  }
  abort() { this.aborted = true; }
}
async function mountEditor(form, body, label = 'متن مطلب') {
  if (window.CKEDITOR && window.DOMPurify) {
    const textarea = $('#body');
    const host = document.createElement('div'); host.className = 'editor-box';
    const mount = document.createElement('div'); host.append(mount); textarea.after(host);
    const C = window.CKEDITOR;
    // Match the former panel's CKEditor tools, with a current local build.
    const plugins = ['Essentials','Paragraph','Heading','Bold','Italic','Underline','Strikethrough',
      'BlockQuote','Link','List','Indent','IndentBlock','Alignment','RemoveFormat','PasteFromOffice',
      'Table','TableToolbar','TableProperties','TableCellProperties','Image','ImageCaption',
      'ImageStyle','ImageResize','ImageToolbar','ImageUpload','ImageInsertViaUrl','HorizontalLine',
      'SpecialCharacters','SpecialCharactersEssentials','SourceEditing','GeneralHtmlSupport',
      'FontFamily','FontSize','FontColor','FontBackgroundColor','Fullscreen'];
    const submit = form.querySelector('button[type=submit]'); submit.disabled = true;
    try {
      const editor = await C.ClassicEditor.create(mount, {
        licenseKey: 'GPL', plugins: plugins.map(name => C[name]),
        language: {ui: 'fa', content: 'fa'},
        initialData: window.DOMPurify.sanitize(body),
        placeholder: 'متن نوشته را اینجا بنویسید…',
        toolbar: {items: ['undo','redo','|','link','uploadImage','insertImageViaUrl','insertTable','horizontalLine',
          'specialCharacters','|','sourceEditing','fullscreen','-','heading','fontFamily','fontSize',
          '|','bold','italic','underline','strikethrough','removeFormat','|','bulletedList','numberedList',
          'outdent','indent','blockQuote','alignment','|','fontColor','fontBackgroundColor'], shouldNotGroupWhenFull: true},
        heading: {options: [{model: 'paragraph',title: 'متن معمولی',class: 'ck-heading_paragraph'},
          ...[1,2,3].map(n => ({model: `heading${n}`,view: `h${n}`,title: `عنوان ${digits(n)}`,class: `ck-heading_heading${n}`}))]},
        fontFamily: {options: ['default','Vazirmatn','Tahoma, sans-serif','Arial, Helvetica, sans-serif','Times New Roman, Times, serif'],supportAllValues: true},
        fontSize: {options: [12,14,16,18,20,24,28,36],supportAllValues: true},
        table: {contentToolbar: ['tableColumn','tableRow','mergeTableCells','tableProperties','tableCellProperties']},
        image: {toolbar: ['imageTextAlternative','toggleImageCaption','imageStyle:inline','imageStyle:block','imageStyle:side','resizeImage']},
        htmlSupport: {allow: [{name: /^(p|div|span|br|h[1-6]|pre|blockquote|ul|ol|li|a|img|figure|figcaption|video|source|table|thead|tbody|tfoot|tr|th|td|strong|b|em|i|u|s|sub|sup|hr)$/,styles: true,classes: true,attributes: true}]},
      });
      if (!form.isConnected) { await editor.destroy(); return; }
      editor.plugins.get('FileRepository').createUploadAdapter = loader => new ImageUploadAdapter(loader);
      replaceToolbarIcons(editor);
      const toolbar = editor.ui.view.toolbar.element.querySelector('.ck-toolbar__items');
      const imageButton = document.createElement('button');
      imageButton.type = 'button';
      imageButton.className = 'ck ck-button ck-button_with-text divan-toolbar-control divan-image-button';
      imageButton.setAttribute('aria-label', 'بارگذاری و درج تصویر از هاست');
      imageButton.title = 'بارگذاری یا انتخاب تصویر از کتابخانه';
      imageButton.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span class="ck-button__label">تصویر</span>';
      imageButton.addEventListener('click', () => mediaLibrary(editor, 'image'));
      const videoButton = document.createElement('button');
      videoButton.type = 'button';
      videoButton.className = 'ck ck-button ck-button_with-text divan-toolbar-control divan-video-button';
      videoButton.setAttribute('aria-label', 'بارگذاری و درج ویدیو از هاست');
      videoButton.title = 'بارگذاری یا انتخاب ویدیو از هاست';
      videoButton.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3z"/></svg><span class="ck-button__label">ویدیو</span>';
      videoButton.addEventListener('click', () => mediaLibrary(editor, 'video'));
      if (toolbar) {
        const ckeImage = [...toolbar.querySelectorAll('button')].find(button => /image|تصویر/i.test(button.getAttribute('aria-label') || ''));
        if (ckeImage) {
          ckeImage.after(imageButton);
          imageButton.after(videoButton);
        } else {
          toolbar.append(imageButton, videoButton);
        }
      } else host.prepend(imageButton, videoButton);
      textarea.hidden = true;
      editor.editing.view.change(writer => writer.setAttribute('aria-label', label, editor.editing.view.document.getRoot()));
      let changed = false;
      editor.model.document.on('change:data', () => { changed = true; markDirty(); });
      // Source mode holds edits outside the model until getData() synchronizes it.
      host.addEventListener('input', markDirty);
      state.editor = {
        instance: editor,
        checkDirty: () => { editor.getData(); return changed; },
        getData: () => window.DOMPurify.sanitize(editor.getData()),
        setReadOnly: blocked => blocked ? editor.enableReadOnlyMode('saving') : editor.disableReadOnlyMode('saving'),
        destroy: () => { editor.destroy().catch(() => {}); host.remove(); },
      };
    } catch {
      host.remove();
      formMessage(form, 'ویرایشگر بارگیری نشد؛ متن در کادر HTML محفوظ است.');
    } finally { submit.disabled = false; }
  }
}
function setEditing(form, blocked) {
  for (const control of form.querySelectorAll('input,select,textarea,button[type=button]')) control.disabled = blocked;
  if (state.editor) state.editor.setReadOnly(blocked);
}
function bindForm(form, action, getFields, verify, onSuccess, before = null) {
  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = form.querySelector('button[type=submit]');
    if (submit.disabled) return;
    submit.disabled = true;
    try {
      if (state.pending) {
        setEditing(form, true);
        if (!(await state.pending.verify())) throw new ApiError('نتیجه هنوز تأیید نشد. ابتدا فهرست سرور را بررسی کنید؛ درخواست دوباره ارسال نمی‌شود.');
        const confirmed = state.pending;
        await onSuccess(confirmed.response);
        state.pending = null; state.dirty = false; notice('نتیجهٔ ذخیره روی سرور تأیید شد.'); return;
      }
      const fields = getFields();
      setEditing(form, true);
      const context = before ? await before(fields) : null;
      const pending = {verify: () => verify(fields, context)};
      try {
        const response = await api.call(action, {method: 'POST', fields});
        state.pending = {...pending, response};
        submit.textContent = 'بررسی نتیجه';
        await onSuccess(response);
        state.pending = null; state.dirty = false;
        notice('تغییرات روی سرور ذخیره شد.');
      } catch (error) {
        if (error.uncertain) { state.pending = pending; submit.textContent = 'بررسی نتیجه'; }
        throw error;
      }
    } catch (error) {
      if (error.status === 401) lock(error.message);
      formMessage(form, error.message);
    } finally { setEditing(form, Boolean(state.pending)); submit.disabled = false; }
  });
}
async function deleteItem(kind, id, label) {
  if (state.pending) { notice('ابتدا نتیجهٔ ارسال قبلی را بررسی کنید.', true); return; }
  if (!window.confirm(`«${label}» به‌صورت دائمی حذف شود؟${kind === 'category' ? '\nدسته باید خالی باشد؛ مطالب آن خودکار حذف نمی‌شوند.' : ''}`)) return;
  const verify = async () => kind === 'category' ? !(await api.call('')).some(c => String(c.cid) === id) : (await api.call('', {query: {nid: id}})).length === 0;
  try {
    await api.call(kind === 'category' ? 'category_delete' : 'delete', {method: 'POST', fields: {id}});
    await render(kind === 'category' ? 'categories' : 'posts'); notice('حذف روی سرور انجام شد.');
  } catch (error) {
    if (error.uncertain) {
      if (await verify().catch(() => false)) { await render(kind === 'category' ? 'categories' : 'posts'); notice('حذف تأیید شد.'); }
      else { state.pending = {verify}; notice('نتیجهٔ حذف نامشخص است. دکمهٔ «بررسی نتیجهٔ حذف» فقط از سرور می‌خواند.', true); $('#content').insertAdjacentHTML('afterbegin', '<button id="check-delete">بررسی نتیجهٔ حذف</button>'); }
    } else { if (error.status === 401) lock(error.message); notice(error.message, true); }
  }
}
async function render(route) {
  if (!api.token) return;
  const generation = ++state.generation;
  $('#content').setAttribute('aria-busy', 'true');
  const [view, id] = route.split('/');
  if (view !== 'logout') await api.call('me');
  let html = ''; let after = null;
  if (view === 'home') {
    const [data, recent, rows, appRes] = await Promise.all([
      api.call('stats'),
      api.call('posts', {query: {page: 1}}),
      categories(),
      api.call('app_info').catch(() => null),
    ]);
    const appInfo = appRes?.app;
    html = `<section class="dashboard-hero"><div><span class="section-kicker">فضای مدیریت شما</span><h1>به دیوان خوش آمدید.</h1><p>نوشته‌ای تازه منتشر کنید یا به سراغ نوشته‌های قبلی بروید.</p></div><svg class="hero-mark" aria-hidden="true"><use href="#i-book"/></svg></section>
      <div class="grid stats"><section class="card"><span class="stat-icon">${icon('book')}</span><div><strong>${digits(data.stats.posts)}</strong><small>نوشته در دیوان</small></div></section><section class="card"><span class="stat-icon gold">${icon('grid')}</span><div><strong>${digits(data.stats.categories)}</strong><small>دسته‌بندی</small></div></section></div>
      <section class="card app-quick-card"><div class="app-quick-info"><span class="stat-icon">${icon('download')}</span><div><strong>دریافت آخرین نسخهٔ اپلیکیشن اندروید (APK)</strong><small>${appInfo?.available ? `نسخه ${e(appInfo.version)} · حجم: ${digits(appInfo.size_human)}${appInfo.updated_at ? ` · آخرین به‌روزرسانی: ${e(displayDate(appInfo.updated_at))}` : ''}` : 'فایل آماده دانلود است'}</small></div></div><div class="app-quick-actions"><a class="button primary compact" href="${e(appInfo?.download_url || '/download/app')}" target="_blank" download>${icon('download')} دانلود فایل APK</a>${button('جزئیات و مدیریت', 'app')}</div></section>
      <div class="dashboard-columns"><section><div class="section-heading"><h2>تازه‌ترین نوشته‌ها</h2>${button('همهٔ نوشته‌ها', 'posts')}</div><div class="card">${recent.posts.slice(0,5).map(p => `<div class="recent-row"><span class="mini-book">${icon('book')}</span><div><strong>${e(p.news_heading)}</strong><small>${e(p.news_date)}</small></div>${button('مطالعه', `read/${p.nid}`)}</div>`).join('') || '<p class="empty">اولین نوشتهٔ دیوان را منتشر کنید.</p>'}<div class="actions" style="margin:20px 0 0">${button('نوشتن مطلب جدید', 'post-new')}</div></div></section><section><div class="section-heading"><h2>دسته‌بندی‌ها</h2>${button('مدیریت دسته‌ها', 'categories')}</div><div class="card">${rows.slice(0,4).map(c => {const src = imageUrl(c.category_image, api.endpoint);return `<a class="mini-category" href="#posts/category_id=${e(c.cid)}" data-route="posts/category_id=${e(c.cid)}">${src ? `<img src="${e(src)}" alt="" loading="lazy">` : icon('grid')}<div><strong>${e(c.category_name)}</strong><small>${e(c.author)}</small></div></a>`;}).join('') || '<p class="empty">دسته‌بندی‌ای ثبت نشده است.</p>'}</div></section></div>`;
  } else if (view === 'posts') {
    await categories();
    const search = new URLSearchParams(id || '');
    const page = Number(search.get('page') || 1); const q = search.get('q') || ''; const cat = search.get('category_id') || '';
    const query = {page, q, ...(cat ? {category_id: cat} : {})};
    const data = await api.call('posts', {query});
    html = `<div class="actions"><h1>نوشته‌ها</h1>${button('مطلب جدید', 'post-new')}</div><form id="search" class="filters"><input name="q" value="${e(q)}" placeholder="جستجو در نوشته‌ها" aria-label="جستجو"><select name="category_id" aria-label="دسته">${categoryOptions(cat, true)}</select><button>جستجو</button></form><section class="card table-wrap"><table><thead><tr><th>عنوان</th><th>دسته</th><th>تاریخ‌ها</th><th>عملیات</th></tr></thead><tbody>${data.posts.map(p => `<tr><td><strong>${e(p.news_heading)}</strong><br><small>${e(p.news_date)}</small></td><td><span class="category-badge">${e(state.categories.find(c => String(c.cid) === String(p.cat_id))?.category_name || p.cat_id)}</span></td><td><small>ایجاد: ${e(displayDate(p.created_at))}<br>ویرایش: ${e(displayDate(p.updated_at))}</small></td><td><div class="actions">${button('مطالعه', `read/${p.nid}`)}${button('ویرایش', `post/${p.nid}`)}<button class="danger" data-delete="post" data-id="${e(p.nid)}" data-label="${e(p.news_heading)}">حذف</button></div></td></tr>`).join('')}</tbody></table>${data.posts.length ? '' : '<p class="empty">نوشته‌ای پیدا نشد.</p>'}<div class="pagination">${page > 1 ? button('صفحه قبل', `posts/${new URLSearchParams({...query, page: page - 1})}`) : ''}<span>${digits(page)} · ${digits(data.total)} نوشته</span>${page * 50 < data.total ? button('صفحه بعد', `posts/${new URLSearchParams({...query, page: page + 1})}`) : ''}</div></section>`;
    after = () => $('#search').addEventListener('submit', event => { event.preventDefault(); navigate(`posts/${new URLSearchParams(new FormData(event.target))}`); });
  } else if (view === 'media') {
    const result = await api.call('media_list');
    const items = result.media || result.videos || [];
    html = `<div class="actions"><h1>کتابخانهٔ رسانه</h1><button type="button" class="primary" id="open-media-library">بارگذاری و مدیریت فایل‌ها</button></div><p class="help">${digits(items.length)} فایل روی هاست؛ نام نمایشی را ویرایش کنید یا فایل را حذف کنید.</p><section class="grid media-library-grid">${items.map(item => {let src;try{src=sameSiteMediaUrl(item.url)}catch{return ''}return `<article class="card media-library-card">${item.type==='video'?`<video controls preload="metadata" src="${e(src)}"></video>`:`<img src="${e(src)}" alt="${e(item.name)}" loading="lazy">`}<strong>${e(item.name)}</strong><small>${item.type==='video'?'ویدیو':'تصویر'} · ${e(displayDate(item.created_at))}</small></article>`}).join('') || '<p class="empty">هنوز رسانه‌ای بارگذاری نشده است.</p>'}</section>`;
    after = () => { $('#open-media-library').onclick = () => mediaLibrary(); };
  } else if (view === 'support' && !id) {
    const result = await api.call('support_list');
    html = `<div class="actions"><h1>پیام‌های پشتیبانی</h1><span class="category-badge">${digits(result.messages.length)} گفتگو</span></div><section class="card table-wrap"><table class="support-table"><thead><tr><th>کاربر</th><th>شماره</th><th>آخرین پیام</th><th>تاریخ</th><th>وضعیت</th></tr></thead><tbody>${result.messages.map(item => {
      const replies = item.replies && item.replies.length ? item.replies : (item.reply ? [{sender: 'admin', message: item.reply, created_at: item.replied_at}] : []);
      const last = replies.length ? replies[replies.length - 1] : {sender: 'user', message: item.message, created_at: item.created_at};
      const preview = (last.message || '').length > 60 ? (last.message || '').slice(0, 60) + '…' : (last.message || '');
      const isPending = last.sender === 'user';
      return `<tr class="support-row" data-route="support/${e(item.id)}" role="button" tabindex="0"><td><strong>${e(item.user_name || 'حساب قدیمی')}</strong></td><td dir="ltr"><small>${e(item.user_mobile || '—')}</small></td><td class="support-preview"><span class="preview-sender">${last.sender === 'user' ? 'کاربر: ' : 'مدیر: '}</span>${e(preview)}</td><td><small>${e(displayDate(last.created_at || item.created_at))}</small></td><td>${isPending ? `<span class="badge-pending">پیام جدید کاربر</span>` : `<span class="badge-replied">پاسخ داده‌شده</span>`}</td></tr>`;
    }).join('') || ''}</tbody></table>${result.messages.length ? '' : '<p class="empty">هنوز پیامی دریافت نشده است.</p>'}</section>`;
    after = () => {
      for (const row of document.querySelectorAll('.support-row')) {
        row.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); navigate(row.dataset.route); } });
      }
    };
  } else if (view === 'support' && id) {
    const result = await api.call('support_list');
    const item = result.messages.find(m => String(m.id) === String(id));
    if (!item) throw new ApiError('گفتگوی پشتیبانی پیدا نشد.');
    const thread = [{id: null, sender: 'user', message: item.message, created_at: item.created_at}];
    if (item.replies && item.replies.length) {
      for (const r of item.replies) thread.push({id: r.id, sender: r.sender || 'admin', message: r.message, created_at: r.created_at});
    } else if (item.reply) {
      thread.push({id: 'legacy', sender: 'admin', message: item.reply, created_at: item.replied_at || item.created_at});
    }
    html = `<div class="actions"><h1>گفتگوی پشتیبانی</h1>${button('بازگشت به فهرست', 'support')}</div><section class="card support-chat"><div class="support-chat-header"><div class="support-chat-user">${icon('user')}<div><strong>${e(item.user_name || 'حساب قدیمی')}</strong><small>${e(item.user_mobile || '—')}</small></div></div><div class="support-chat-actions"><span class="category-badge">${digits(thread.length)} پیام</span><button type="button" class="compact" id="refresh-chat">تازه‌سازی</button></div></div><div class="support-chat-messages" id="chat-messages">${thread.map(msg => {
      const isAdmin = msg.sender === 'admin';
      const senderTag = isAdmin ? 'پشتیبانی دیوان' : e(item.user_name || 'کاربر');
      const actions = isAdmin ? `<div class="chat-bubble-tools"><button type="button" class="bubble-btn" data-edit-reply="${e(msg.id)}">ویرایش</button><button type="button" class="bubble-btn bubble-btn-danger" data-delete-reply="${e(msg.id)}">حذف</button></div>` : '';
      return `<div class="chat-bubble chat-bubble-${isAdmin ? 'admin' : 'user'}"><div class="chat-bubble-top"><span class="chat-sender-tag">${senderTag}</span>${actions}</div><p class="chat-text">${e(msg.message)}</p><small>${e(displayDate(msg.created_at))}</small></div>`;
    }).join('')}</div><form class="support-reply-form" data-support-reply="${e(item.id)}"><label>پاسخ شما<textarea name="reply" maxlength="4000" rows="3" placeholder="پاسخ تازه برای کاربر بنویسید… (ارسال با دکمه یا Ctrl+Enter)"></textarea></label><div class="support-reply-actions"><p class="form-message" role="alert"></p><button class="primary compact" type="submit">ارسال پاسخ</button></div></form></section>`;
    after = () => {
      const messagesBox = $('#chat-messages');
      if (messagesBox) messagesBox.scrollTop = messagesBox.scrollHeight;
      const refreshBtn = $('#refresh-chat');
      if (refreshBtn) refreshBtn.onclick = () => render(`support/${id}`);
      for (const btn of document.querySelectorAll('[data-edit-reply]')) {
        btn.onclick = async () => {
          const replyId = btn.dataset.editReply;
          const bubble = btn.closest('.chat-bubble');
          const currentText = bubble?.querySelector('.chat-text')?.textContent || '';
          const newText = prompt('متن جدید پیام:', currentText);
          if (newText === null) return;
          const trimmed = newText.trim();
          if (!trimmed || trimmed === currentText) return;
          try {
            await api.call('support_reply_update', {
              method: 'POST',
              fields: {id: replyId, ticket_id: id, message: trimmed}
            });
            await render(`support/${id}`);
            notice('پیام با موفقیت ویرایش شد.');
          } catch (error) {
            alert(error.message || 'خطا در ویرایش پیام');
          }
        };
      }
      for (const btn of document.querySelectorAll('[data-delete-reply]')) {
        btn.onclick = async () => {
          const replyId = btn.dataset.deleteReply;
          if (!confirm('آیا از حذف این پیام اطمینان دارید؟')) return;
          try {
            await api.call('support_reply_delete', {
              method: 'POST',
              fields: {id: replyId, ticket_id: id}
            });
            await render(`support/${id}`);
            notice('پیام با موفقیت حذف شد.');
          } catch (error) {
            alert(error.message || 'خطا در حذف پیام');
          }
        };
      }
      const form = document.querySelector('[data-support-reply]');
      const textarea = form?.querySelector('textarea');
      let isSubmitting = false;
      if (textarea) {
        textarea.addEventListener('keydown', event => {
          if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            if (event.repeat) return;
            event.preventDefault();
            if (!isSubmitting) form.requestSubmit();
          }
        });
      }
      if (form) {
        form.addEventListener('input', () => { state.dirty = true; });
        form.addEventListener('submit', async event => {
          event.preventDefault();
          if (isSubmitting) return;
          const text = form.elements.reply.value.trim();
          if (!text) return;
          const submit = form.querySelector('button[type=submit]');
          if (submit && submit.disabled) return;
          isSubmitting = true;
          if (submit) {
            submit.disabled = true;
            submit.textContent = 'در حال ارسال…';
          }
          if (textarea) textarea.disabled = true;
          try {
            await api.call('support_reply', {method: 'POST', fields: {id: form.dataset.supportReply, reply: text}});
            state.dirty = false;
            await render(`support/${id}`);
            notice('پاسخ برای کاربر ثبت شد.');
          }
          catch (error) {
            isSubmitting = false;
            if (error.status === 401) lock(error.message);
            formMessage(form, error.message);
            if (submit) {
              submit.disabled = false;
              submit.textContent = 'ارسال پاسخ';
            }
            if (textarea) textarea.disabled = false;
          }
        });
      }
    };
  } else if (view === 'users') {
    const result = await api.call('users');
    html = `<div class="actions"><h1>کاربران اپ</h1><span class="category-badge">${digits(result.users.length)} حساب</span></div><p class="help">حساب‌های ساخته‌شده با تأیید شمارهٔ موبایل؛ همان حساب برای ارسال و پیگیری پیام‌های پشتیبانی استفاده می‌شود.</p><section class="card table-wrap"><table><thead><tr><th>نام</th><th>شمارهٔ موبایل</th><th>عضویت</th><th>پیام‌های پشتیبانی</th></tr></thead><tbody>${result.users.map(user => `<tr><td><strong>${e(user.name || '—')}</strong></td><td dir="ltr">${e(user.mobile)}</td><td><small>${e(displayDate(user.created_at))}</small></td><td>${digits(user.support_messages)}</td></tr>`).join('')}</tbody></table>${result.users.length ? '' : '<p class="empty">هنوز کاربری ثبت‌نام نکرده است.</p>'}</section>`;
  } else if (view === 'read') {
    const article = (await api.call('', {query: {nid: id}}))[0];
    if (!article) throw new ApiError('مطلب دیگر موجود نیست.');
    html = `<div class="actions"><h1>${e(article.news_heading)}</h1>${button('ویرایش', `post/${id}`)}${button('بازگشت', 'posts')}</div><p>${e(article.news_date)}</p><p class="article-dates">ایجاد: ${e(displayDate(article.created_at))}　·　آخرین ویرایش: ${e(displayDate(article.updated_at))}</p><iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="متن مطلب"></iframe>`;
    after = () => preview($('.preview'), article.news_description);
  } else if (view === 'post' || view === 'post-new') {
    await categories();
    const original = view === 'post' ? (await api.call('', {query: {nid: id}}))[0] : null;
    if (view === 'post' && !original) throw new ApiError('مطلب دیگر موجود نیست.');
    if (!state.categories.length) { html = `<section class="card"><h1>ابتدا دسته بسازید</h1>${button('دسته‌بندی‌ها', 'categories')}</section>`; }
    else {
      html = `<div class="actions"><h1>${original ? 'ویرایش مطلب' : 'مطلب جدید'}</h1>${button('بازگشت', 'posts')}</div>${original ? `<p class="article-dates">ایجاد: ${e(displayDate(original.created_at))}　·　آخرین ویرایش: ${e(displayDate(original.updated_at))}</p>` : ''}<form id="post-form" class="card form-card"><label>عنوان<input name="news_heading" maxlength="500" required value="${e(original?.news_heading)}"></label><label>عنوان فرعی<input name="news_date" maxlength="255" required value="${e(original?.news_date)}"></label><label>دسته<select name="cid">${categoryOptions(original?.cat_id)}</select></label><label>متن مطلب<textarea id="body" name="news_description">${e(original?.news_description)}</textarea></label><p class="help">برای ویدیو، دکمهٔ «ویدیو» کنار دکمهٔ تصویر در نوار بالای ویرایشگر را بزنید. برای عکس از دکمهٔ تصویر استفاده کنید.</p><details><summary>پیش‌نمایش متن</summary><button id="refresh-preview" type="button">نمایش متن فعلی</button><iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="پیش‌نمایش متن"></iframe></details><p class="form-message" role="alert"></p><button type="submit" class="primary">ذخیره روی سرور</button></form>`;
      after = async () => {
        const form = $('#post-form');
        const body = original?.news_description || '';
        await mountEditor(form, body);
        $('#refresh-preview').onclick = () => preview($('.preview'), editorHtml(body));
        const fields = () => ({...Object.fromEntries(new FormData(form)), news_description: editorHtml(body), ...(original ? {id: String(id)} : {})});
        bindForm(form, original ? 'update' : 'create', fields,
          async (target, baseline) => matchingWrite(await api.call('', {query: {cat_id: target.cid}}), target, baseline).length === 1,
          () => render('posts'),
          async target => original ? [] : (await api.call('', {query: {cat_id: target.cid}})).map(p => String(p.nid)));
      };
    }
  } else if (view === 'categories') {
    const rows = await categories();
    html = `<div class="actions"><h1>دسته‌بندی‌ها</h1>${button('دسته جدید', 'category-new')}</div><div class="grid">${rows.map(c => { const src = imageUrl(c.category_image, api.endpoint); return `<section class="card category-card">${src ? `<img src="${e(src)}" alt="${e(c.category_name)}" loading="lazy">` : '<p class="muted">بدون تصویر</p>'}<h2>${e(c.category_name)}</h2><p>${e(c.author)}</p><div class="actions">${button('ویرایش', `category/${c.cid}`)}<button class="danger" data-delete="category" data-id="${e(c.cid)}" data-label="${e(c.category_name)}">حذف</button></div></section>`; }).join('') || '<p>دسته‌ای ثبت نشده است.</p>'}</div>`;
  } else if (view === 'category' || view === 'category-new') {
    await categories();
    const original = view === 'category' ? state.categories.find(c => String(c.cid) === String(id)) : null;
    if (view === 'category' && !original) throw new ApiError('دسته دیگر موجود نیست.');
    const src = original ? imageUrl(original.category_image, api.endpoint) : '';
    html = `<div class="actions"><h1>${original ? 'ویرایش دسته' : 'دسته جدید'}</h1>${button('بازگشت', 'categories')}</div><form id="category-form" class="card form-card"><label>نام دسته<input name="category_name" maxlength="255" required value="${e(original?.category_name)}"></label><label>نویسنده<input name="author" maxlength="50" required value="${e(original?.author)}"></label>${src ? `<img class="current-image" src="${e(src)}" alt="تصویر فعلی">` : ''}<label>تصویر دسته<input name="category_image" type="file" accept="image/jpeg,image/png,image/gif" ${original ? '' : 'required'}></label><p class="help">JPEG، PNG یا GIF تا ۵ مگابایت.${original ? ' انتخاب نکردن تصویر، تصویر فعلی را نگه می‌دارد.' : ''}</p><p class="form-message" role="alert"></p><button class="primary" type="submit">ذخیره روی سرور</button></form>`;
    after = () => {
      const form = $('#category-form');
      bindForm(form, original ? 'category_update' : 'category_create', () => {
        const fields = new FormData(form); if (original) fields.set('id', id); return fields;
      }, async (fields, baseline) => {
        const file = fields.get('category_image');
        const matches = (await api.call('')).filter(c => (original ? String(c.cid) === String(id) : !baseline.includes(String(c.cid))) && c.category_name === fields.get('category_name') && c.author === fields.get('author') && (file?.size ? !!c.category_image && c.category_image !== original?.category_image : c.category_image === original?.category_image));
        return matches.length === 1;
      }, () => render('categories'), () => Promise.resolve(state.categories.map(c => String(c.cid))));
    };
  } else if (view === 'pages') {
    const rows = (await api.call('pages')).pages;
    html = `<h1>صفحه‌های دیوان</h1><div class="grid">${rows.map(p => `<section class="card"><h2>${e(p.title)}</h2><div class="actions">${button('ویرایش', `page/${p.slug}`)}</div></section>`).join('')}</div>`;
  } else if (view === 'page') {
    const original = (await api.call('pages')).pages.find(p => p.slug === id);
    if (!original) throw new ApiError('صفحه موجود نیست.');
    html = `<div class="actions"><h1>ویرایش ${e(original.title)}</h1>${button('بازگشت', 'pages')}</div><form id="page-form" class="card form-card"><label>عنوان<input name="title" maxlength="255" required value="${e(original.title)}"></label><label>متن صفحه<textarea id="body" name="html_body">${e(original.html_body)}</textarea></label><details><summary>پیش‌نمایش متن</summary><button id="refresh-preview" type="button">نمایش متن فعلی</button><iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="پیش‌نمایش متن"></iframe></details><p class="form-message" role="alert"></p><button type="submit" class="primary">ذخیره روی سرور</button></form>`;
    after = async () => {
      const form = $('#page-form');
      await mountEditor(form, original.html_body, 'متن صفحه');
      $('#refresh-preview').onclick = () => preview($('.preview'), editorHtml(original.html_body));
      bindForm(form, 'page_update', () => ({...Object.fromEntries(new FormData(form)), html_body: editorHtml(original.html_body), slug: id, revision: String(original.revision)}),
        async target => (await api.call('pages')).pages.some(p => p.slug === target.slug && p.title === target.title && p.html_body === target.html_body && p.revision === Number(target.revision) + 1),
        () => render('pages'));
    };
  } else if (view === 'app') {
    const result = await api.call('app_info');
    const app = result?.app || {};
    html = `<div class="actions"><h1>دانلود و مدیریت اپلیکیشن اندروید</h1><a class="button primary compact" href="${e(app.download_url || '/download/app')}" target="_blank" download>${icon('download')} دریافت فایل APK</a></div><p class="help">نسخهٔ رسمی اندروید اپلیکیشن دیوان انصارالحسین(ع). لینک مستقیم را برای کاربران ارسال کنید یا فایل جدید را مستقیماً بارگذاری نمایید.</p><div class="grid app-detail-grid"><section class="card"><h2>مشخصات آخرین نسخه</h2><div class="app-meta-list"><div class="app-meta-row"><span>نام برنامه:</span><strong>${e(app.name || 'دیوان انصارالحسین(ع)')}</strong></div><div class="app-meta-row"><span>نسخه:</span><strong>${e(app.version || '—')}</strong></div><div class="app-meta-row"><span>نام فایل:</span><code>${e(app.filename || 'divan-ansaralhossein.apk')}</code></div><div class="app-meta-row"><span>حجم فایل:</span><strong>${digits(app.size_human || '—')}</strong></div><div class="app-meta-row"><span>آخرین به‌روزرسانی:</span><strong>${e(displayDate(app.updated_at))}</strong></div><div class="app-meta-row"><span>وضعیت فایل:</span><span class="${app.available ? 'badge-replied' : 'badge-pending'}">${app.available ? 'آماده برای دانلود' : 'فایل یافت نشد'}</span></div></div><div class="actions" style="margin-top:16px"><a class="button primary compact" href="${e(app.download_url || '/download/app')}" target="_blank" download>${icon('download')} دانلود مستقیم APK</a><button type="button" id="copy-download-link">کپی لینک دانلود</button></div></section><section class="card form-card"><h2>بارگذاری نسخهٔ جدید APK</h2><p class="help">با بارگذاری فایل جدید با پسوند apk.، نسخه قبلی جایگزین می‌شود و کاربران همیشه جدیدترین فایل را دریافت خواهند کرد.</p><form id="app-upload-form"><label>انتخاب فایل APK جدید<input name="app_apk" type="file" accept=".apk,application/vnd.android.package-archive" required></label><p class="form-message" role="alert"></p><button class="primary" type="submit">بارگذاری و ثبت در سرور</button></form></section></div>`;
    after = () => {
      const copyBtn = $('#copy-download-link');
      if (copyBtn) {
        copyBtn.onclick = async () => {
          const downloadUrl = new URL(app.download_url || '/download/app', window.location.href).href;
          try {
            await navigator.clipboard.writeText(downloadUrl);
            notice('لینک مستقیم دانلود در حافظه کپی شد.');
          } catch {
            window.prompt('لینک مستقیم دانلود:', downloadUrl);
          }
        };
      }
      const form = $('#app-upload-form');
      if (form) {
        form.addEventListener('submit', async event => {
          event.preventDefault();
          const fileInput = form.elements.namedItem('app_apk');
          const file = fileInput?.files?.[0];
          if (!file) return;
          const submit = form.querySelector('button[type=submit]');
          if (submit && submit.disabled) return;
          if (submit) {
            submit.disabled = true;
            submit.textContent = 'در حال بارگذاری فایل APK…';
          }
          formMessage(form, 'در حال ارسال فایل APK به سرور…', false);
          try {
            const formData = new FormData(form);
            await api.call('app_upload', {method: 'POST', fields: formData});
            notice('نسخهٔ جدید اپلیکیشن با موفقیت بارگذاری و جایگزین شد.');
            await render('app');
          } catch (error) {
            if (error.status === 401) lock(error.message);
            formMessage(form, error.message);
          } finally {
            if (submit) {
              submit.disabled = false;
              submit.textContent = 'بارگذاری و ثبت در سرور';
            }
          }
        });
      }
    };
  } else if (view === 'account') {
    const data = (await api.call('account')).account;
    html = `<h1>حساب مدیر</h1><form id="account-form" class="card form-card"><p>نام کاربری: ${e(data.Username)}</p><label>ایمیل<input name="email" type="email" maxlength="100" required dir="ltr" value="${e(data.Email)}"></label><details><summary>تغییر رمز ورود</summary><label>رمز فعلی<input name="old_password" type="password" autocomplete="current-password"></label><label>رمز جدید<input name="new_password" type="password" autocomplete="new-password"></label><label>تکرار رمز جدید<input name="confirm_password" type="password" autocomplete="new-password"></label><p class="help">بعد از تغییر رمز، در پنل و اپ دوباره وارد شوید.</p></details><p class="form-message" role="alert"></p><button class="primary" type="submit">ذخیره مشخصات</button></form>`;
    after = () => {
      const form = $('#account-form');
      bindForm(form, 'account_update', () => Object.fromEntries(new FormData(form)), async target => !target.new_password && (await api.call('account')).account.Email === target.email, response => {
        if (response?.reauthenticate) { lock('رمز تغییر کرد؛ با رمز جدید وارد شوید.'); return; }
        return render('account');
      });
    };
  } else if (view === 'logout') { await logout(); return; }
  else { return render('home'); }
  if (generation !== state.generation) return;
  disposeEditor(); state.route = route; history.replaceState(null, '', `#${route}`); $('#content').innerHTML = html;
  for (const a of document.querySelectorAll('nav a')) a.setAttribute('aria-current', a.hash === `#${view}` ? 'page' : 'false');
  $('#content').setAttribute('aria-busy', 'false');
  if (after) await after();
  window.scrollTo?.({top: 0, behavior: 'instant'});
}
async function navigate(route) {
  if ((state.dirty || state.pending) && !window.confirm('تغییرات این صفحه ممکن است هنوز ثبت نشده باشد. صفحه را ترک می‌کنید؟')) return;
  notice();
  try { await render(route); state.dirty = false; state.pending = null; } catch (error) { if (error.status === 401) lock(error.message); notice(error.message, true); $('#content').setAttribute('aria-busy', 'false'); }
}
async function logout() {
  if ((state.dirty || state.pending) && !window.confirm('تغییرات یا نتیجهٔ ارسال هنوز نهایی نشده است. از مدیریت خارج می‌شوید؟')) return;
  let message = 'از مدیریت خارج شدید.';
  try { await api.logout(); } catch { message = 'دسترسی این مرورگر پاک شد؛ لغو توکن روی سرور تأیید نشد.'; }
  disposeEditor(); state.dirty = false; state.pending = null; state.route = ''; $('#content').textContent = ''; lock(message);
}
$('#login-form').addEventListener('submit', async event => {
  event.preventDefault(); const form = event.target; const submit = form.querySelector('button'); submit.disabled = true;
  try {
    const me = await api.login(form.elements.namedItem('username').value.trim(), form.elements.namedItem('password').value);
    showPanel(me.username);
    // An expired login may hide a still-unsaved form. Resume it after login.
    if (!state.route || (!state.dirty && !state.pending)) await render(initialRoute());
  } catch (error) { $('#login-message').textContent = error.message; $('#login-message').className = 'error'; }
  finally { form.elements.namedItem('password').value = ''; submit.disabled = false; }
});
$('#logout').onclick = logout;
document.addEventListener('click', event => {
  const route = event.target.closest('[data-route]');
  if (route) { event.preventDefault(); navigate(route.dataset.route); return; }
  const nav = event.target.closest('nav a');
  if (nav) { event.preventDefault(); navigate(nav.hash.slice(1)); return; }
  const deletion = event.target.closest('[data-delete]');
  if (deletion) { deletion.disabled = true; deleteItem(deletion.dataset.delete, deletion.dataset.id, deletion.dataset.label).finally(() => { deletion.disabled = false; }); return; }
  if (event.target.id === 'check-delete' && state.pending) {
    event.target.disabled = true;
    state.pending.verify().then(async ok => { if (ok) { state.pending = null; await render(state.route); notice('حذف تأیید شد.'); } else notice('نتیجه هنوز تأیید نشد؛ درخواست حذف تکرار نمی‌شود.', true); }).catch(error => notice(error.message, true)).finally(() => { event.target.disabled = false; });
  }
});
window.addEventListener('beforeunload', event => { if (state.dirty || state.pending) { event.preventDefault(); event.returnValue = ''; } });
function initialRoute() {
  const old = {'dashboard.php': 'home', 'story.php': 'posts', 'category.php': 'categories', 'admin.php': 'account', 'setting.php': 'account', 'add-menu.php': 'post-new', 'edit-menu.php': 'post', 'delete-menu.php': 'post', 'menu-detail.php': 'read', 'add-category.php': 'category-new', 'edit-category.php': 'category', 'delete-category.php': 'category', 'app.php': 'app', 'download.php': 'app', 'logout.php': 'logout'};
  const name = location.pathname.split('/').pop(); const id = new URLSearchParams(location.search).get('id');
  const view = old[name] || 'home'; return location.hash.slice(1) || view + (id && /^[1-9][0-9]*$/.test(id) ? `/${id}` : '');
}
if (api.token) {
  try { const me = await api.call('me'); showPanel(me.username); await render(initialRoute()); }
  catch (error) { lock(error.message); }
}
