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
function bindCategoryOrder(rows) {
  const list = $('#category-list'), status = $('#category-order-status');
  if (!list) return;
  let baseline = rows.map(row => String(row.cid));
  let saving = false;
  let savePending = false;
  let draggedRow = null;
  let activeRow = null;
  let startY = null;
  let isDragging = false;

  const ids = () => [...list.querySelectorAll('[data-category-id]')].map(row => row.dataset.categoryId);

  function updateRanks() {
    const children = [...list.children].filter(el => el.hasAttribute('data-category-id'));
    children.forEach((row, index) => {
      const rank = row.querySelector('.category-position');
      if (rank) rank.textContent = digits(index + 1);
      const up = row.querySelector('.btn-order-up');
      if (up) up.disabled = index === 0;
      const down = row.querySelector('.btn-order-down');
      if (down) down.disabled = index === children.length - 1;
    });
  }

  async function saveOrder() {
    const ordered = ids();
    if (JSON.stringify(ordered) === JSON.stringify(baseline)) return;
    if (saving) {
      savePending = true;
      return;
    }
    saving = true;
    savePending = false;
    if (status) status.textContent = 'در حال ذخیره ترتیب…';

    try {
      await api.call('category_reorder', {
        method: 'POST',
        fields: {ids: JSON.stringify(ordered), previous_ids: JSON.stringify(baseline)}
      });
      baseline = ordered;
      state.categories = ordered.map(id => rows.find(r => String(r.cid) === id)).filter(Boolean);
      state.dirty = false;
      if (status) status.textContent = 'ترتیب جدید ذخیره شد.';
      setTimeout(() => {
        if (status && status.textContent === 'ترتیب جدید ذخیره شد.') {
          status.textContent = '';
        }
      }, 3000);
    } catch (error) {
      if (status) status.textContent = error.message;
      if (error.status === 401) lock(error.message);
    } finally {
      saving = false;
      if (savePending) {
        saveOrder();
      }
    }
  }

  function swapRows(source, target, clientY) {
    if (!source || !target || source === target || !list.contains(target)) return;
    const all = [...list.querySelectorAll('[data-category-id]')];
    const srcIdx = all.indexOf(source);
    const tgtIdx = all.indexOf(target);
    if (srcIdx === -1 || tgtIdx === -1) return;

    const rect = target.getBoundingClientRect();
    if (srcIdx < tgtIdx) {
      if (clientY > rect.top + rect.height * 0.25) {
        list.insertBefore(source, target.nextSibling);
        updateRanks();
      }
    } else {
      if (clientY < rect.bottom - rect.height * 0.25) {
        list.insertBefore(source, target);
        updateRanks();
      }
    }
  }

  updateRanks();

  list.addEventListener('click', event => {
    const upBtn = event.target.closest('.btn-order-up');
    if (upBtn) {
      event.preventDefault();
      const row = upBtn.closest('[data-category-id]');
      const prev = row?.previousElementSibling;
      if (prev && prev.hasAttribute('data-category-id')) {
        list.insertBefore(row, prev);
        updateRanks();
        upBtn.focus();
        saveOrder();
      }
      return;
    }
    const downBtn = event.target.closest('.btn-order-down');
    if (downBtn) {
      event.preventDefault();
      const row = downBtn.closest('[data-category-id]');
      const next = row?.nextElementSibling;
      if (next && next.hasAttribute('data-category-id')) {
        list.insertBefore(next, row);
        updateRanks();
        downBtn.focus();
        saveOrder();
      }
    }
  });

  list.addEventListener('dragstart', event => {
    if (event.target.closest('button:not(.category-drag)')) {
      event.preventDefault();
      return;
    }
    const row = event.target.closest('[data-category-id]');
    if (!row) return;
    draggedRow = row;
    row.classList.add('dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', row.dataset.categoryId || '');
    }
  });

  list.addEventListener('dragover', event => {
    if (!draggedRow) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    const rows = [...list.querySelectorAll('[data-category-id]')];
    const target = rows.find(r => {
      if (r === draggedRow) return false;
      const rect = r.getBoundingClientRect();
      return event.clientY >= rect.top && event.clientY <= rect.bottom;
    });
    if (target) {
      swapRows(draggedRow, target, event.clientY);
    }
  });

  const onDragFinish = () => {
    if (draggedRow) {
      draggedRow.classList.remove('dragging');
      draggedRow = null;
      updateRanks();
      saveOrder();
    }
  };

  list.addEventListener('dragend', onDragFinish);
  list.addEventListener('drop', event => {
    event.preventDefault();
    onDragFinish();
  });

  function onPointerMove(event) {
    if (!activeRow) return;
    if (!isDragging) {
      if (Math.abs(event.clientY - startY) < 4) return;
      isDragging = true;
      activeRow.classList.add('dragging');
    }

    const rows = [...list.querySelectorAll('[data-category-id]')];
    let target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-category-id]');
    if (!target || target === activeRow || !list.contains(target)) {
      target = rows.find(r => {
        if (r === activeRow) return false;
        const rect = r.getBoundingClientRect();
        return event.clientY >= rect.top && event.clientY <= rect.bottom;
      });
    }
    if (target) {
      swapRows(activeRow, target, event.clientY);
    }
    if (event.clientY < 90) window.scrollBy(0, -18);
    else if (event.clientY > window.innerHeight - 90) window.scrollBy(0, 18);
  }

  function onPointerUp() {
    if (!activeRow) return;
    const row = activeRow;
    activeRow = null;
    startY = null;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerUp);
    if (isDragging) {
      isDragging = false;
      row.classList.remove('dragging');
      updateRanks();
      saveOrder();
    }
  }

  for (const handle of list.querySelectorAll('.category-drag')) {
    const row = handle.closest('[data-category-id]');
    handle.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      activeRow = row;
      startY = event.clientY;
      isDragging = false;
      window.addEventListener('pointermove', onPointerMove);
      window.addEventListener('pointerup', onPointerUp);
      window.addEventListener('pointercancel', onPointerUp);
      try { handle.setPointerCapture(event.pointerId); } catch (_) {}
    });

    handle.addEventListener('pointermove', onPointerMove);
    handle.addEventListener('pointerup', onPointerUp);
    handle.addEventListener('pointercancel', onPointerUp);

    handle.addEventListener('keydown', event => {
      if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
      event.preventDefault();
      const other = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
      if (other && other.hasAttribute('data-category-id')) {
        list.insertBefore(row, event.key === 'ArrowUp' ? other : other.nextSibling);
        handle.focus();
        updateRanks();
        saveOrder();
      }
    });
  }
}
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
  const actionName = kind === 'category' ? 'category_delete' : kind === 'announcement' ? 'announcement_delete' : 'delete';
  const targetRoute = kind === 'category' ? 'categories' : kind === 'announcement' ? 'announcements' : 'posts';
  const verify = async () => {
    if (kind === 'category') return !(await api.call('')).some(c => String(c.cid) === id);
    if (kind === 'announcement') return !(await api.call('announcements')).announcements.some(a => String(a.id) === id);
    return (await api.call('', {query: {nid: id}})).length === 0;
  };
  try {
    await api.call(actionName, {method: 'POST', fields: {id}});
    await render(targetRoute); notice('حذف روی سرور انجام شد.');
  } catch (error) {
    if (error.uncertain) {
      if (await verify().catch(() => false)) { await render(targetRoute); notice('حذف تأیید شد.'); }
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
      api.call('app_releases').catch(() => null),
    ]);
    const latest = appRes?.latest || appRes?.releases?.[0] || null;
    html = `<section class="dashboard-hero"><div><span class="section-kicker">فضای مدیریت شما</span><h1>به دیوان خوش آمدید.</h1><p>نوشته‌ای تازه منتشر کنید یا به سراغ نوشته‌های قبلی بروید.</p></div><svg class="hero-mark" aria-hidden="true"><use href="#i-book"/></svg></section>
      <div class="grid stats"><section class="card"><span class="stat-icon">${icon('book')}</span><div><strong>${digits(data.stats.posts)}</strong><small>نوشته در دیوان</small></div></section><section class="card"><span class="stat-icon gold">${icon('grid')}</span><div><strong>${digits(data.stats.categories)}</strong><small>دسته‌بندی</small></div></section></div>
      <section class="card app-quick-card"><div class="app-quick-info"><span class="stat-icon">${icon('download')}</span><div><strong>دریافت آخرین نسخهٔ اپلیکیشن اندروید (APK)</strong><small>${latest ? `نسخه ${e(latest.version)} · حجم: ${digits(latest.size_human)}${latest.created_at ? ` · آخرین به‌روزرسانی: ${e(displayDate(latest.created_at))}` : ''}` : 'فایل آماده دانلود است'}</small></div></div><div class="app-quick-actions"><a class="button primary compact" href="${e(latest?.download_url || '/mobile-api.php?action=app_download')}" download="${e(latest?.filename || 'divan-ansaralhossein.apk')}">${icon('download')} دانلود فایل APK</a>${button('جدول نسخه‌ها و مدیریت', 'app')}</div></section>
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
    html = `
      <div class="actions">
        <h1>کاربران اپلیکیشن</h1>
        <span class="category-badge">${digits(result.users.length)} حساب</span>
      </div>
      <p class="help">فهرست حساب‌های ساخته‌شده در اپلیکیشن. مدیران تعیین‌شده در این بخش، می‌توانند مستقیماً از طریق اپلیکیشن نیز به پیام‌های پشتیبانی کاربران پاسخ دهند.</p>
      <section class="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>نام و نام خانوادگی</th>
              <th>نام پدر</th>
              <th>شمارهٔ همراه</th>
              <th>نقش کاربری</th>
              <th>تاریخ عضویت</th>
              <th>پیام‌های پشتیبانی</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            ${result.users.map(user => {
              const isAdmin = Boolean(user.is_admin);
              const badge = isAdmin
                ? '<span class="category-badge" style="background:#dbeafe;color:#1e40af;font-weight:700">مدیر اپلیکیشن</span>'
                : '<span class="category-badge">کاربر عادی</span>';
              const actionBtn = isAdmin
                ? `<button type="button" class="danger compact" data-admin-toggle="${e(user.id)}" data-admin-value="0">لغو مدیریت</button>`
                : `<button type="button" class="compact" data-admin-toggle="${e(user.id)}" data-admin-value="1">تبدیل به مدیر</button>`;
              return `<tr>
                <td><strong>${e(user.name || '—')}</strong></td>
                <td><small>${e(user.father_name || '—')}</small></td>
                <td dir="ltr">${e(user.mobile)}</td>
                <td>${badge}</td>
                <td><small>${e(displayDate(user.created_at))}</small></td>
                <td>${digits(user.support_messages)}</td>
                <td>${actionBtn}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
        ${result.users.length ? '' : '<p class="empty">هنوز کاربری ثبت‌نام نکرده است.</p>'}
      </section>
    `;
    after = () => {
      document.querySelectorAll('[data-admin-toggle]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const userId = btn.dataset.adminToggle;
          const setAdmin = btn.dataset.adminValue === '1';
          btn.disabled = true;
          try {
            await api.call('user_set_admin', {method: 'POST', fields: {id: userId, is_admin: setAdmin ? '1' : '0'}});
            notice(setAdmin ? 'کاربر به عنوان مدیر اپلیکیشن تعیین شد.' : 'مدیریت اپلیکیشن لغو شد.');
            await render('users');
          } catch (err) {
            btn.disabled = false;
            alert(err.message || 'خطا در تغییر وضعیت مدیر');
          }
        });
      });
    };
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
    html = `<div class="actions"><h1>دسته‌بندی‌ها</h1><span id="category-order-status" role="status" aria-live="polite"></span>${button('دسته جدید', 'category-new')}</div>
      <ol id="category-list" class="category-list" aria-label="ترتیب دسته‌بندی‌ها">${rows.map((c, index) => { const src = imageUrl(c.category_image, api.endpoint); return `<li class="category-row" data-category-id="${e(c.cid)}" draggable="true">
        <button type="button" class="category-drag" aria-label="جابه‌جایی ${e(c.category_name)}" title="بکشید یا با دکمه‌های بالا/پایین جابه‌جا کنید"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h.01M16 5h.01M8 12h.01M16 12h.01M8 19h.01M16 19h.01" stroke-width="4"/></svg></button>
        <div class="category-order-controls">
          <button type="button" class="btn-order-move btn-order-up" data-move-up="${e(c.cid)}" aria-label="انتقال ${e(c.category_name)} به بالا" title="انتقال به بالا">▲</button>
          <span class="category-position">${digits(index + 1)}</span>
          <button type="button" class="btn-order-move btn-order-down" data-move-down="${e(c.cid)}" aria-label="انتقال ${e(c.category_name)} به پایین" title="انتقال به پایین">▼</button>
        </div>
        ${src ? `<img src="${e(src)}" alt="" loading="lazy" draggable="false">` : '<span class="category-thumbnail">بدون تصویر</span>'}
        <div class="category-description"><strong>${e(c.category_name)}</strong><small>${e(c.author)}</small></div>
        <div class="actions">${button('ویرایش', `category/${c.cid}`)}<button class="danger" data-delete="category" data-id="${e(c.cid)}" data-label="${e(c.category_name)}">حذف</button></div></li>`; }).join('') || '<li class="empty">دسته‌ای ثبت نشده است.</li>'}</ol>`;
    after = () => bindCategoryOrder(rows);
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
  } else if (view === 'announcements' || view === 'pages' || view === 'announcements-public') {
    const [result, usersRes] = await Promise.all([
      api.call('announcements'),
      api.call('users').catch(() => ({users: []})),
    ]);
    const announcements = result.announcements || [];
    const publicCount = announcements.filter(a => a.is_public).length;
    const privateCount = announcements.filter(a => !a.is_public).length;
    const iconSymbols = {
      campaign: '📢',
      event: '📅',
      book: '📖',
      message: '✉️',
      star: '⭐',
      info: 'ℹ️',
      volume: '🔊',
      bookmark: '🔖',
    };

    html = `
      <div class="actions">
        <h1>اعلام‌ها و اخبار مهم</h1>
        ${button('اعلام جدید', 'announcement-new')}
      </div>
      <div class="filters" style="margin-bottom:16px;padding:12px 18px;display:flex;gap:10px;align-items:center;background:#fff;border-radius:14px;border:1px solid var(--line)">
        <span style="font-size:13px;font-weight:600;color:var(--muted)">نمایش:</span>
        <button type="button" id="tab-all" class="compact primary" style="min-height:36px;padding:6px 14px;font-size:13px">همه (${digits(announcements.length)})</button>
        <button type="button" id="tab-public" class="compact" style="min-height:36px;padding:6px 14px;font-size:13px">عمومی — صفحه اول (${digits(publicCount)})</button>
        <button type="button" id="tab-private" class="compact" style="min-height:36px;padding:6px 14px;font-size:13px">خصوصی — کاربران (${digits(privateCount)})</button>
      </div>
      <p class="help">
        اعلام‌های عمومی در صفحه نخست اپلیکیشن برای همه نمایش داده می‌شوند؛ اعلام‌های اختصاصی فقط برای کاربران انتخاب‌شده در برگه اعلام‌ها نمایش داده می‌شوند.
      </p>
      <section class="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>عنوان اعلام</th>
              <th>نوع / گیرندگان</th>
              <th>آیکون</th>
              <th>عنوان فرعی / تاریخ</th>
              <th>تاریخ ثبت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody id="announcements-tbody">
            ${announcements.map(a => {
              const count = a.user_ids ? a.user_ids.length : 0;
              const typeBadge = a.is_public
                ? '<span class="category-badge" style="background:#dcfce7;color:#166534;font-weight:700">عمومی (صفحه اول)</span>'
                : (count > 0 
                    ? `<span class="category-badge" style="background:#e0e7ff;color:#3730a3">خصوصی (${digits(count)} کاربر)</span>`
                    : '<span class="category-badge" style="background:#fee2e2;color:#991b1b">خصوصی (بدون کاربر)</span>');
              const sym = iconSymbols[a.icon] || '📢';
              return `<tr data-public="${a.is_public ? '1' : '0'}">
                <td><strong>${e(a.title)}</strong></td>
                <td>${typeBadge}</td>
                <td><span style="font-size:18px" title="${e(a.icon || 'campaign')}">${sym}</span></td>
                <td><small>${e(a.subtitle || '—')}</small></td>
                <td><small>${e(displayDate(a.created_at))}</small></td>
                <td>
                  <div class="table-actions">
                    ${button('مشاهده', `announcement-read/${a.id}`)}
                    ${button('ویرایش', `announcement/${a.id}`)}
                    <button type="button" class="danger compact" data-delete="announcement" data-id="${e(a.id)}" data-label="${e(a.title)}">حذف</button>
                  </div>
                </td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
        ${announcements.length ? '' : '<p class="empty">هنوز هیچ اعلامی در این بخش ثبت نشده است.</p>'}
      </section>
    `;

    after = () => {
      const tbody = $('#announcements-tbody');
      const btnAll = $('#tab-all');
      const btnPublic = $('#tab-public');
      const btnPrivate = $('#tab-private');

      const setTab = mode => {
        [btnAll, btnPublic, btnPrivate].forEach(b => b?.classList.remove('primary'));
        if (mode === 'public') btnPublic?.classList.add('primary');
        else if (mode === 'private') btnPrivate?.classList.add('primary');
        else btnAll?.classList.add('primary');

        if (tbody) {
          for (const row of tbody.querySelectorAll('tr')) {
            const isPub = row.dataset.public === '1';
            if (mode === 'public') row.style.display = isPub ? '' : 'none';
            else if (mode === 'private') row.style.display = !isPub ? '' : 'none';
            else row.style.display = '';
          }
        }
      };

      btnAll?.addEventListener('click', () => setTab('all'));
      btnPublic?.addEventListener('click', () => setTab('public'));
      btnPrivate?.addEventListener('click', () => setTab('private'));
    };
  } else if (view === 'announcement' || view === 'announcement-new') {
    const [originalRes, usersRes] = await Promise.all([
      view === 'announcement' ? api.call('announcements', {query: {id}}) : Promise.resolve(null),
      api.call('users').catch(() => ({users: []})),
    ]);
    const original = originalRes?.announcement || null;
    if (view === 'announcement' && !original) throw new ApiError('اعلام مورد نظر یافت نشد.');
    const users = usersRes.users || [];
    const selectedIds = new Set((original?.user_ids || []).map(String));
    const isPublicDefault = original ? !!original.is_public : false;
    const currentIcon = original?.icon || 'campaign';

    const iconChoices = [
      {id: 'campaign', label: 'اطلاعیه و خبر', sym: '📢'},
      {id: 'event', label: 'مراسم و هیئت', sym: '📅'},
      {id: 'book', label: 'کتاب و سخن', sym: '📖'},
      {id: 'message', label: 'پیام و ارتباط', sym: '✉️'},
      {id: 'star', label: 'ویژه و مهم', sym: '⭐'},
      {id: 'info', label: 'راهنما و نکات', sym: 'ℹ️'},
      {id: 'volume', label: 'صوت و نوا', sym: '🔊'},
      {id: 'bookmark', label: 'نشانک و یادآوری', sym: '🔖'},
    ];

    html = `
      <div class="actions">
        <h1>${original ? 'ویرایش اعلام' : 'اعلام جدید'}</h1>
        ${button('بازگشت', 'announcements')}
      </div>
      ${original ? `<p class="article-dates">ایجاد: ${e(displayDate(original.created_at))}　·　آخرین ویرایش: ${e(displayDate(original.updated_at))}</p>` : ''}
      <form id="announcement-form" class="card form-card">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:14px 18px;margin-bottom:20px">
          <label style="display:flex;align-items:center;gap:12px;margin:0;cursor:pointer">
            <input type="checkbox" name="is_public" id="is_public_cb" ${isPublicDefault ? 'checked' : ''} style="width:22px;height:22px;min-height:auto;flex:none;accent-color:#166534">
            <div>
              <strong style="color:#166534;font-size:15px;display:block">اعلام عمومی (نمایش در صفحه اول برنامه)</strong>
              <small style="color:#15803d;display:block;margin-top:2px">این اعلام در صفحه اول اپلیکیشن برای همهٔ کاربران نمایش داده می‌شود و نیاز به تعیین گیرنده ندارد.</small>
            </div>
          </label>
        </div>
        <label>عنوان اعلام
          <input name="title" maxlength="500" required value="${e(original?.title || '')}" placeholder="مثال: ساعت برگزاری مراسم هفتگی یا سخن اول">
        </label>
        <label>عنوان فرعی یا تاریخ
          <input name="subtitle" maxlength="255" value="${e(original?.subtitle || '')}" placeholder="مثال: اطلاعیه شماره ۱ — پاییز ۱۴۰۳">
        </label>

        <label>آیکون موضوعی اعلام
          <div class="announcement-icon-picker" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px">
            ${iconChoices.map(item => `
              <label class="icon-choice-label" style="display:flex;align-items:center;gap:8px;padding:8px 14px;border:1.5px solid ${currentIcon === item.id ? '#153f37' : '#d7e0d8'};border-radius:12px;background:${currentIcon === item.id ? '#edf4ee' : '#fff'};cursor:pointer;font-size:14px;transition:all .15s">
                <input type="radio" name="icon" value="${item.id}" ${currentIcon === item.id ? 'checked' : ''} style="display:none">
                <span style="font-size:18px">${item.sym}</span>
                <strong>${item.label}</strong>
              </label>
            `).join('')}
          </div>
        </label>

        <div class="users-selection-box" id="users-selection-box">
          <div class="users-selection-header">
            <div>
              <strong>انتخاب کاربران مجاز برای مشاهده این اعلام</strong>
              <p class="help" style="margin:2px 0 0">این اعلام فقط برای کاربرانی که تیک خورده باشند در بخش اعلام‌های اپ موبایل نمایش داده می‌شود.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span id="users-count-badge" class="category-badge">${digits(selectedIds.size)} کاربر انتخاب شده</span>
              <button type="button" id="btn-select-all" class="compact">انتخاب همه</button>
              <button type="button" id="btn-deselect-all" class="compact">لغو همه</button>
            </div>
          </div>
          <div style="margin-bottom:8px">
            <input type="search" id="user-search-input" placeholder="جستجوی نام یا شماره همراه کاربر…" style="font-size:13px;padding:6px 10px">
          </div>
          <div class="users-checkbox-list" id="users-list">
            ${users.map(u => {
              const isChecked = selectedIds.has(String(u.id));
              return `<label class="user-check-item" data-search="${e((u.name || '') + ' ' + (u.mobile || ''))}">
                <input type="checkbox" name="user_id" value="${e(u.id)}" ${isChecked ? 'checked' : ''}>
                <div class="user-check-info">
                  <strong>${e(u.name || 'کاربر بدون نام')}</strong>
                  <small dir="ltr">${e(u.mobile || '')}</small>
                </div>
              </label>`;
            }).join('')}
            ${users.length ? '' : '<p class="empty" style="padding:10px">هنوز کاربری در سامانه ثبت‌نام نکرده است.</p>'}
          </div>
        </div>
        <label>متن و محتوای اعلام
          <textarea id="body" name="description">${e(original?.description || '')}</textarea>
        </label>
        <p class="help">می‌توانید از دکمه‌های درج تصویر و ویدیو در نوار ابزار بالا برای غنی‌تر کردن متن اعلام استفاده کنید.</p>
        <details>
          <summary>پیش‌نمایش متن</summary>
          <button id="refresh-preview" type="button">نمایش متن فعلی</button>
          <iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="پیش‌نمایش اعلام"></iframe>
        </details>
        <p class="form-message" role="alert"></p>
        <button type="submit" class="primary">ذخیره روی سرور</button>
      </form>
    `;

    after = async () => {
      const form = $('#announcement-form');
      const body = original?.description || '';
      await mountEditor(form, body, 'متن اعلام');
      $('#refresh-preview').onclick = () => preview($('.preview'), editorHtml(body));

      const isPublicCb = form.querySelector('#is_public_cb');
      const usersBox = form.querySelector('#users-selection-box');
      const toggleUsersBox = () => {
        if (usersBox) {
          usersBox.style.display = (isPublicCb && isPublicCb.checked) ? 'none' : 'block';
        }
      };
      if (isPublicCb) {
        isPublicCb.addEventListener('change', () => {
          toggleUsersBox();
          markDirty();
        });
        toggleUsersBox();
      }

      form.querySelectorAll('.icon-choice-label').forEach(lbl => {
        lbl.addEventListener('click', () => {
          form.querySelectorAll('.icon-choice-label').forEach(l => {
            l.style.borderColor = '#d7e0d8';
            l.style.background = '#fff';
          });
          lbl.style.borderColor = '#153f37';
          lbl.style.background = '#edf4ee';
          const radio = lbl.querySelector('input[type=radio]');
          if (radio) radio.checked = true;
          markDirty();
        });
      });

      const countBadge = $('#users-count-badge');
      const updateCount = () => {
        const checkedCount = form.querySelectorAll('.user-check-item input[type=checkbox]:checked').length;
        if (countBadge) countBadge.textContent = `${digits(checkedCount)} کاربر انتخاب شده`;
      };

      const userSearch = $('#user-search-input');
      if (userSearch) {
        userSearch.addEventListener('input', () => {
          const q = userSearch.value.trim().toLowerCase();
          for (const item of form.querySelectorAll('.user-check-item')) {
            const str = (item.dataset.search || '').toLowerCase();
            item.style.display = (!q || str.includes(q)) ? '' : 'none';
          }
        });
      }

      $('#btn-select-all')?.addEventListener('click', () => {
        for (const cb of form.querySelectorAll('.user-check-item input[type=checkbox]')) {
          const item = cb.closest('.user-check-item');
          if (item && item.style.display !== 'none') {
            cb.checked = true;
          }
        }
        updateCount();
        markDirty();
      });

      $('#btn-deselect-all')?.addEventListener('click', () => {
        for (const cb of form.querySelectorAll('.user-check-item input[type=checkbox]')) {
          const item = cb.closest('.user-check-item');
          if (item && item.style.display !== 'none') {
            cb.checked = false;
          }
        }
        updateCount();
        markDirty();
      });

      form.querySelector('#users-list')?.addEventListener('change', () => {
        updateCount();
        markDirty();
      });

      const fields = () => {
        const isPub = form.elements.namedItem('is_public')?.checked ? 1 : 0;
        const checkedIds = isPub ? [] : [...form.querySelectorAll('.user-check-item input[type=checkbox]:checked')].map(cb => cb.value);
        const chosenIcon = form.querySelector('input[name="icon"]:checked')?.value || 'campaign';
        return {
          title: form.elements.namedItem('title').value,
          subtitle: form.elements.namedItem('subtitle').value,
          description: editorHtml(body),
          is_public: isPub,
          icon: chosenIcon,
          user_ids: isPub ? '[]' : JSON.stringify(checkedIds),
          ...(original ? {id: String(id)} : {}),
        };
      };

      bindForm(
        form,
        original ? 'announcement_update' : 'announcement_create',
        fields,
        async target => {
          const list = (await api.call('announcements')).announcements;
          return list.some(a => (original ? String(a.id) === String(id) : true) && a.title === target.title);
        },
        () => render('announcements')
      );
    };
  } else if (view === 'announcement-read') {
    const [result, usersRes] = await Promise.all([
      api.call('announcements', {query: {id}}),
      api.call('users').catch(() => ({users: []})),
    ]);
    const a = result.announcement;
    if (!a) throw new ApiError('اعلام پیدا نشد.');
    const usersMap = new Map((usersRes.users || []).map(u => [String(u.id), u]));
    const targetNames = (a.user_ids || []).map(uid => usersMap.get(String(uid))?.name || `کاربر ${uid}`).join('، ');
    const viewers = a.viewers || [];
    const comments = a.comments || [];
    const commentsMap = new Map(comments.map(c => [String(c.id), c]));
    const childrenMap = new Map();
    const rootComments = [];
    for (const c of comments) {
      const pid = c.parent_id ? String(c.parent_id) : null;
      if (pid && commentsMap.has(pid)) {
        if (!childrenMap.has(pid)) childrenMap.set(pid, []);
        childrenMap.get(pid).push(c);
      } else {
        rootComments.push(c);
      }
    }
    const treeComments = [];
    function traverseComments(c, depth) {
      treeComments.push({
        ...c,
        _depth: depth,
        _parent: c.parent_id ? commentsMap.get(String(c.parent_id)) : null,
      });
      const children = childrenMap.get(String(c.id)) || [];
      for (const child of children) {
        traverseComments(child, depth + 1);
      }
    }
    for (const root of rootComments) {
      traverseComments(root, 0);
    }

    html = `
      <div class="actions">
        <h1>${e(a.title)}</h1>
        ${button('ویرایش', `announcement/${id}`)}
        ${button('بازگشت', a.is_public ? 'announcements-public' : 'announcements')}
      </div>
      <p><strong>${e(a.subtitle || '')}</strong></p>
      <p class="article-dates">ایجاد: ${e(displayDate(a.created_at))}　·　آخرین ویرایش: ${e(displayDate(a.updated_at))}</p>
      <p class="help">
        ${a.is_public
          ? '<span class="category-badge" style="background:#dcfce7;color:#166534;font-weight:700">اعلام عمومی — نمایش در صفحه نخست اپلیکیشن برای همه</span>'
          : `<strong>کاربران مخاطب (${digits((a.user_ids || []).length)} نفر):</strong> ${e(targetNames || 'هیچ کاربری انتخاب نشده است')}`}
      </p>
      <iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="متن اعلام"></iframe>

      <div class="section-heading" style="margin-top:36px">
        <h2>مشاهده‌کنندگان اعلام <small style="font-size:13px;font-weight:400;color:var(--muted)">(اختصاصی مدیر)</small></h2>
        <span class="category-badge" style="background:#dcfce7;color:#166534;font-weight:700">${digits(viewers.length)} نفر باز کرده‌اند</span>
      </div>
      <section class="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>نام کاربر</th>
              <th>نام پدر</th>
              <th>شمارهٔ همراه</th>
              <th>زمان اولین مشاهده</th>
            </tr>
          </thead>
          <tbody>
            ${viewers.map(v => `<tr>
              <td><strong>${e(v.name || '—')}</strong></td>
              <td><small>${e(v.father_name || '—')}</small></td>
              <td dir="ltr">${e(v.mobile)}</td>
              <td><small>${e(displayDate(v.viewed_at))}</small></td>
            </tr>`).join('')}
          </tbody>
        </table>
        ${viewers.length ? '' : '<p class="empty">هنوز هیچ کاربری این اعلام را در اپلیکیشن باز نکرده است.</p>'}
      </section>

      <div class="section-heading" style="margin-top:36px">
        <h2>نظرات و گفتگوهای کاربران</h2>
        <span class="category-badge">${digits(comments.length)} نظر</span>
      </div>
      <section class="card">
        ${comments.length ? `<div style="display:grid;gap:16px;padding:8px 0">
          ${treeComments.map(c => {
            const parentName = c._parent ? (c._parent.user_is_admin ? 'پشتیبان دیوان' : (c._parent.user_name || 'کاربر')) : '';
            const indent = Math.min(c._depth * 28, 56);
            return `
              <div style="background:#f8faf8;border:1px solid var(--line);border-radius:14px;padding:16px 20px;${indent > 0 ? `margin-right:${indent}px;border-right:3px solid var(--accent);` : ''}">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                  <div style="display:flex;align-items:center;gap:10px">
                    <strong>${c.user_is_admin ? 'پشتیبان دیوان انصارالحسین(ع)' : e(c.user_name || 'کاربر')}</strong>
                    ${c.user_is_admin ? `<small style="color:var(--muted)">(${e(c.user_name)})</small><span class="category-badge" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;font-size:11px">پشتیبان دیوان</span>` : ''}
                    ${parentName ? `<span style="font-size:12px;color:var(--muted)">در پاسخ به ${e(parentName)}</span>` : ''}
                  </div>
                  <div style="display:flex;align-items:center;gap:12px">
                    <small style="color:var(--muted);font-size:12px">${e(displayDate(c.created_at))}</small>
                    <button type="button" class="danger compact" data-delete-comment="${e(c.id)}" style="min-height:34px;padding:4px 10px;font-size:12px">حذف نظر</button>
                  </div>
                </div>
                <p style="margin:0;line-height:1.8;white-space:pre-wrap">${e(c.comment)}</p>
              </div>
            `;
          }).join('')}
        </div>` : '<p class="empty" style="margin:0">هنوز نظری برای این اعلام ثبت نشده است.</p>'}
      </section>
    `;
    after = () => {
      preview($('.preview'), a.description);
      document.querySelectorAll('[data-delete-comment]').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm('آیا از حذف این نظر اطمینان دارید؟')) return;
          const commentId = btn.dataset.deleteComment;
          btn.disabled = true;
          try {
            await api.call('announcement_comment_delete', {method: 'POST', fields: {id: commentId}});
            notice('نظر حذف شد.');
            await render(`announcement-read/${id}`);
          } catch (err) {
            btn.disabled = false;
            alert(err.message || 'خطا در حذف نظر');
          }
        });
      });
    };
  } else if (view === 'app') {
    const result = await api.call('app_releases');
    const releases = result?.releases || [];
    const latest = result?.latest || releases.find(r => r.is_latest) || releases[0] || null;
    html = `
      <div class="actions">
        <h1>مدیریت و نسخه‌های اپلیکیشن اندروید</h1>
        ${latest ? `<a class="button primary compact" href="${e(latest.download_url)}" download="${e(latest.filename)}">${icon('download')} دریافت آخرین نسخه (APK)</a>` : ''}
      </div>
      <p class="help">فهرست کامل نسخه‌های منتشرشدهٔ اپلیکیشن دیوان انصارالحسین(ع). می‌توانید نسخه‌های مختلف را در جدول مشاهده و مستقیماً دانلود کنید، یا نسخهٔ جدید بارگذاری نمایید.</p>

      ${latest ? `
      <section class="card app-quick-card">
        <div class="app-quick-info">
          <span class="stat-icon">${icon('download')}</span>
          <div>
            <strong>آخرین نسخهٔ فعال: نسخه ${e(latest.version)}</strong>
            <small>حجم فایل: ${digits(latest.size_human)} · تاریخ انتشار: ${e(displayDate(latest.created_at))}${latest.changelog ? ` · تغییرات: ${e(latest.changelog)}` : ''}</small>
          </div>
        </div>
        <div class="app-quick-actions">
          <a class="button primary compact" href="${e(latest.download_url)}" download="${e(latest.filename)}">${icon('download')} دانلود مستقیم APK</a>
          <button type="button" id="copy-latest-link">کپی لینک دانلود</button>
        </div>
      </section>
      ` : ''}

      <div class="grid app-detail-grid">
        <section class="card table-wrap" style="grid-column: 1 / -1">
          <div class="section-heading" style="margin-top:0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <div>
              <h2>جدول نسخه‌های اپلیکیشن</h2>
              <span class="category-badge">${digits(releases.length)} نسخه</span>
            </div>
            <button type="button" class="compact secondary" id="scan-host-apks" title="اگر فایل APK را از طریق سی‌پنل یا FTP روی هاست ریخته‌اید، با این دکمه خودکار شناسایی و ثبت می‌شود">
              ${icon('refresh')} شناسایی و ثبت فایل‌های موجود روی هاست
            </button>
          </div>
          <table>
            <thead>
              <tr>
                <th>نسخه</th>
                <th>نام فایل</th>
                <th>حجم</th>
                <th>تاریخ انتشار</th>
                <th>توضیحات و تغییرات</th>
                <th>وضعیت</th>
                <th>عملیات</th>
              </tr>
            </thead>
            <tbody>
              ${releases.map(r => `
                <tr class="${r.is_latest ? 'row-active' : ''}">
                  <td><strong>نسخه ${e(r.version)}</strong></td>
                  <td dir="ltr"><code>${e(r.filename)}</code></td>
                  <td><strong>${digits(r.size_human)}</strong></td>
                  <td><small>${e(displayDate(r.created_at))}</small></td>
                  <td><span class="app-changelog" title="${e(r.changelog || '')}">${e(r.changelog || '—')}</span></td>
                  <td>${r.is_latest ? '<span class="badge-replied">نسخهٔ فعال</span>' : '<span class="category-badge">آرشیو</span>'}</td>
                  <td>
                    <div class="actions" style="margin:0;flex-wrap:nowrap;gap:6px">
                      <a class="button compact" href="${e(r.download_url)}" download="${e(r.filename)}" title="دانلود فایل APK">${icon('download')} دانلود APK</a>
                      ${!r.is_latest ? `<button type="button" class="compact" data-set-latest="${e(r.id)}">فعال‌سازی</button>` : ''}
                      <button type="button" class="compact danger" data-delete-release="${e(r.id)}" data-version="${e(r.version)}">حذف</button>
                    </div>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
          ${releases.length ? '' : '<p class="empty">هنوز هیچ نسخه‌ای ثبت نشده است. از فرم زیر نسخهٔ اول را بارگذاری کنید یا اگر فایل را روی هاست قرار داده‌اید، دکمهٔ «شناسایی و ثبت فایل‌های موجود روی هاست» را بزنید.</p>'}
        </section>

        <section class="card form-card" style="grid-column: 1 / -1; max-width: 700px">
          <h2>بارگذاری نسخهٔ جدید اپلیکیشن (APK)</h2>
          <p class="help">فایل APK را انتخاب کنید و شماره نسخه و تغییرات آن را ثبت فرمایید.</p>
          <form id="app-upload-form">
            <label>شماره نسخه (مثلاً ۱.۰.۱ یا 1.0.1)
              <input name="version" required placeholder="مثال: 1.0.1" maxlength="30">
            </label>
            <label>توضیحات و تغییرات این نسخه
              <textarea name="changelog" rows="3" style="min-height:80px" placeholder="توضیح کوتاه درباره تغییرات این نسخه…"></textarea>
            </label>
            <label>انتخاب فایل APK جدید
              <input name="app_apk" type="file" accept=".apk,application/vnd.android.package-archive" required>
            </label>
            <div class="media-progress" id="apk-progress" hidden style="margin:12px 0;background:var(--bg-accent,#f4f6f8);padding:10px 12px;border-radius:6px">
              <progress id="apk-progress-bar" value="0" max="100" style="width:100%;height:10px"></progress>
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--text-muted);margin-top:6px">
                <span id="apk-progress-text">۰٪</span>
                <span id="apk-progress-bytes"></span>
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:10px;margin-bottom:16px;cursor:pointer">
              <input name="is_latest" type="checkbox" checked style="width:auto;min-height:auto">
              <span>تنظیم به عنوان آخرین نسخهٔ فعال (لینک دانلود اصلی به این نسخه اشاره خواهد کرد)</span>
            </label>
            <p class="form-message" role="alert"></p>
            <button class="primary" type="submit">بارگذاری و ثبت نسخه</button>
          </form>
          <div class="callout" style="margin-top:16px;background:var(--bg-accent,#f0f4f8);padding:12px 16px;border-radius:8px;font-size:13px;color:var(--text-muted);line-height:1.7">
            <strong>💡 راهکار جایگزین در صورت کندی اینترنت یا محدودیت سرور:</strong>
            <p style="margin:6px 0 0">اگر به دلیل حجم فایل APK (~۶۰ مگابایت) یا سرعت اینترنت ارسال فایل از مرورگر زمان‌بر است، می‌توانید فایل APK را مستقیماً از طریق File Manager هاست در پوشهٔ <code>storage/app/apk</code> یا <code>public/download</code> قرار دهید و سپس روی دکمهٔ <strong>«شناسایی و ثبت فایل‌های موجود روی هاست»</strong> در بالای جدول کلیک کنید تا فوراً و بدون معطلی شناسایی و ثبت شود.</p>
          </div>
        </section>
      </div>
    `;
    after = () => {
      const copyBtn = $('#copy-latest-link');
      if (copyBtn) {
        copyBtn.onclick = async () => {
          const downloadUrl = new URL(latest?.download_url || '/mobile-api.php?action=app_download', window.location.href).href;
          try {
            await navigator.clipboard.writeText(downloadUrl);
            notice('لینک مستقیم دانلود در حافظه کپی شد.');
          } catch {
            window.prompt('لینک دانلود:', downloadUrl);
          }
        };
      }
      const scanBtn = $('#scan-host-apks');
      if (scanBtn) {
        scanBtn.onclick = async () => {
          scanBtn.disabled = true;
          const origText = scanBtn.textContent;
          scanBtn.textContent = 'در حال بررسی هاست…';
          try {
            const res = await api.call('app_scan', {method: 'POST'});
            notice(res?.message || 'اسکن هاست انجام شد.');
            await render('app');
          } catch (error) {
            notice(error.message, true);
            scanBtn.disabled = false;
            scanBtn.textContent = origText;
          }
        };
      }
      for (const btn of document.querySelectorAll('[data-set-latest]')) {
        btn.onclick = async () => {
          btn.disabled = true;
          try {
            await api.call('app_set_latest', {method: 'POST', fields: {id: btn.dataset.setLatest}});
            notice('نسخه به عنوان آخرین نسخهٔ فعال تنظیم شد.');
            await render('app');
          } catch (error) {
            notice(error.message, true);
            btn.disabled = false;
          }
        };
      }
      for (const btn of document.querySelectorAll('[data-delete-release]')) {
        btn.onclick = async () => {
          const ver = btn.dataset.version;
          if (!window.confirm(`آیا از حذف نسخه «${ver}» اطمینان دارید؟`)) return;
          btn.disabled = true;
          try {
            await api.call('app_delete', {method: 'POST', fields: {id: btn.dataset.deleteRelease}});
            notice(`نسخه «${ver}» حذف شد.`);
            await render('app');
          } catch (error) {
            notice(error.message, true);
            btn.disabled = false;
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

          const progressBox = $('#apk-progress');
          const progressBar = $('#apk-progress-bar');
          const progressText = $('#apk-progress-text');
          const progressBytes = $('#apk-progress-bytes');

          if (submit) submit.disabled = true;
          if (progressBox) progressBox.hidden = false;
          if (progressBar) progressBar.value = 0;
          if (progressText) progressText.textContent = '۰٪';
          if (progressBytes) progressBytes.textContent = '';
          formMessage(form, 'در حال ارسال فایل APK به سرور…', false);

          const formatBytes = b => {
            if (!b) return '';
            const mb = (b / (1024 * 1024)).toFixed(1);
            return `${digits(mb)} مگابایت`;
          };

          try {
            const formData = new FormData(form);
            await api.upload('app_upload', {
              fields: formData,
              onProgress: (pct, loaded, total) => {
                if (pct !== null) {
                  if (progressBar) progressBar.value = pct;
                  if (progressText) progressText.textContent = `${digits(pct)}٪`;
                  if (loaded && total && progressBytes) {
                    progressBytes.textContent = `${formatBytes(loaded)} از ${formatBytes(total)}`;
                  }
                  if (submit) {
                    submit.textContent = pct < 100
                      ? `در حال ارسال… (${digits(pct)}٪)`
                      : 'در حال ذخیره‌سازی روی سرور…';
                  }
                  if (pct >= 100) {
                    formMessage(form, 'فایل با موفقیت به سرور منتقل شد؛ در حال ذخیره‌سازی و ثبت در جدول…', false);
                  }
                }
              }
            });
            notice('نسخهٔ جدید اپلیکیشن با موفقیت در جدول ثبت شد.');
            await render('app');
          } catch (error) {
            if (error.status === 401) lock(error.message);
            formMessage(form, error.message);
          } finally {
            if (submit) {
              submit.disabled = false;
              submit.textContent = 'بارگذاری و ثبت نسخه';
            }
            if (progressBox) {
              setTimeout(() => { progressBox.hidden = true; }, 1500);
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
  const old = {'dashboard.php': 'home', 'story.php': 'posts', 'category.php': 'categories', 'announcements.php': 'announcements', 'announcement-new.php': 'announcement-new', 'admin.php': 'account', 'setting.php': 'account', 'add-menu.php': 'post-new', 'edit-menu.php': 'post', 'delete-menu.php': 'post', 'menu-detail.php': 'read', 'add-category.php': 'category-new', 'edit-category.php': 'category', 'delete-category.php': 'category', 'app.php': 'app', 'download.php': 'app', 'logout.php': 'logout'};
  const name = location.pathname.split('/').pop(); const id = new URLSearchParams(location.search).get('id');
  const view = old[name] || 'home'; return location.hash.slice(1) || view + (id && /^[1-9][0-9]*$/.test(id) ? `/${id}` : '');
}
if (api.token) {
  try { const me = await api.call('me'); showPanel(me.username); await render(initialRoute()); }
  catch (error) { lock(error.message); }
}
