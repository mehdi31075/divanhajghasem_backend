import {TokenApi, ApiError, escapeHtml as e, imageUrl, matchingWrite} from './client.js';
let storage;
try { storage = window.sessionStorage; } catch { storage = null; }
const api = new TokenApi(new URL('mobile-api.php', document.baseURI), {storage});
const $ = selector => document.querySelector(selector);
const state = {categories: [], dirty: false, pending: null, route: '', generation: 0, editor: null, user: ''};
const digits = value => new Intl.NumberFormat('fa').format(value);
const button = (label, route) => `<button type="button" data-route="${e(route)}">${e(label)}</button>`;
function notice(message = '', error = false) { $('#notice').textContent = message; $('#notice').className = error ? 'error' : 'success'; }
function formMessage(form, message, error = true) {
  const box = form.querySelector('.form-message'); box.textContent = message; box.className = `form-message ${error ? 'error' : 'success'}`;
}
function lock(message) {
  api.setToken(''); $('#panel').hidden = true; $('#login').hidden = false;
  $('#login-message').textContent = message; $('#login-message').className = 'error';
}
function showPanel(user) {
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
function setEditing(form, blocked) {
  for (const control of form.querySelectorAll('input,select,textarea,button[type=button]')) control.disabled = blocked;
  if (form.id === 'post-form' && state.editor) state.editor.setReadOnly(blocked);
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
  const [view, id] = route.split('/');
  if (view !== 'logout') await api.call('me');
  let html = ''; let after = null;
  if (view === 'home') {
    const data = await api.call('stats');
    html = `<h1>پیشخوان</h1><div class="grid stats"><section class="card"><strong>${digits(data.stats.categories)}</strong>دسته‌بندی</section><section class="card"><strong>${digits(data.stats.posts)}</strong>نوشته</section></div><div class="actions">${button('نوشتن مطلب جدید', 'post-new')}${button('مدیریت دسته‌ها', 'categories')}</div>`;
  } else if (view === 'posts') {
    await categories();
    const search = new URLSearchParams(id || '');
    const page = Number(search.get('page') || 1); const q = search.get('q') || ''; const cat = search.get('category_id') || '';
    const query = {page, q, ...(cat ? {category_id: cat} : {})};
    const data = await api.call('posts', {query});
    html = `<div class="actions"><h1>نوشته‌ها</h1>${button('مطلب جدید', 'post-new')}</div><form id="search" class="filters"><input name="q" value="${e(q)}" placeholder="جستجو در نوشته‌ها" aria-label="جستجو"><select name="category_id" aria-label="دسته">${categoryOptions(cat, true)}</select><button>جستجو</button></form><section class="card table-wrap"><table><thead><tr><th>عنوان</th><th>دسته</th><th>عملیات</th></tr></thead><tbody>${data.posts.map(p => `<tr><td><strong>${e(p.news_heading)}</strong><br><small>${e(p.news_date)}</small></td><td>${e(state.categories.find(c => String(c.cid) === String(p.cat_id))?.category_name || p.cat_id)}</td><td><div class="actions">${button('مطالعه', `read/${p.nid}`)}${button('ویرایش', `post/${p.nid}`)}<button class="danger" data-delete="post" data-id="${e(p.nid)}" data-label="${e(p.news_heading)}">حذف</button></div></td></tr>`).join('')}</tbody></table>${data.posts.length ? '' : '<p class="empty">نوشته‌ای پیدا نشد.</p>'}<div class="pagination">${page > 1 ? button('صفحه قبل', `posts/${new URLSearchParams({...query, page: page - 1})}`) : ''}<span>${digits(page)} · ${digits(data.total)} نوشته</span>${page * 50 < data.total ? button('صفحه بعد', `posts/${new URLSearchParams({...query, page: page + 1})}`) : ''}</div></section>`;
    after = () => $('#search').addEventListener('submit', event => { event.preventDefault(); navigate(`posts/${new URLSearchParams(new FormData(event.target))}`); });
  } else if (view === 'read') {
    const article = (await api.call('', {query: {nid: id}}))[0];
    if (!article) throw new ApiError('مطلب دیگر موجود نیست.');
    html = `<div class="actions"><h1>${e(article.news_heading)}</h1>${button('ویرایش', `post/${id}`)}${button('بازگشت', 'posts')}</div><p>${e(article.news_date)}</p><iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="متن مطلب"></iframe>`;
    after = () => preview($('.preview'), article.news_description);
  } else if (view === 'post' || view === 'post-new') {
    await categories();
    const original = view === 'post' ? (await api.call('', {query: {nid: id}}))[0] : null;
    if (view === 'post' && !original) throw new ApiError('مطلب دیگر موجود نیست.');
    if (!state.categories.length) { html = `<section class="card"><h1>ابتدا دسته بسازید</h1>${button('دسته‌بندی‌ها', 'categories')}</section>`; }
    else {
      html = `<div class="actions"><h1>${original ? 'ویرایش مطلب' : 'مطلب جدید'}</h1>${button('بازگشت', 'posts')}</div><form id="post-form" class="card"><label>عنوان<input name="news_heading" maxlength="500" required value="${e(original?.news_heading)}"></label><label>عنوان فرعی<input name="news_date" maxlength="255" required value="${e(original?.news_date)}"></label><label>دسته<select name="cid">${categoryOptions(original?.cat_id)}</select></label><label>متن مطلب<textarea id="body" name="news_description">${e(original?.news_description)}</textarea></label><details><summary>پیش‌نمایش متن</summary><button id="refresh-preview" type="button">نمایش متن فعلی</button><iframe class="preview" sandbox="" referrerpolicy="no-referrer" title="پیش‌نمایش متن"></iframe></details><p class="form-message" role="alert"></p><button type="submit" class="primary">ذخیره روی سرور</button></form>`;
      after = () => {
        const form = $('#post-form');
        const body = original?.news_description || '';
        if (window.CKEDITOR) {
          const submit = form.querySelector('button[type=submit]');
          submit.disabled = true;
          state.editor = window.CKEDITOR.replace('body', {language: 'fa', contentsLangDirection: 'rtl', height: 350, versionCheck: false, removePlugins: 'exportpdf,cloudservices', toolbar: [['Source', 'Undo', 'Redo'], ['Bold', 'Italic', 'Underline'], ['NumberedList', 'BulletedList', 'JustifyRight', 'JustifyCenter'], ['Link', 'Unlink', 'Image', 'Table'], ['Format', 'TextColor']]});
          state.editor.on('instanceReady', () => { state.editor?.resetDirty(); submit.disabled = false; });
          state.editor.on('change', () => { if (state.editor?.checkDirty()) markDirty(); });
        }
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
    html = `<div class="actions"><h1>${original ? 'ویرایش دسته' : 'دسته جدید'}</h1>${button('بازگشت', 'categories')}</div><form id="category-form" class="card"><label>نام دسته<input name="category_name" maxlength="255" required value="${e(original?.category_name)}"></label><label>نویسنده<input name="author" maxlength="50" required value="${e(original?.author)}"></label>${src ? `<img class="current-image" src="${e(src)}" alt="تصویر فعلی">` : ''}<label>تصویر دسته<input name="category_image" type="file" accept="image/jpeg,image/png,image/gif" ${original ? '' : 'required'}></label><p class="help">JPEG، PNG یا GIF تا ۵ مگابایت.${original ? ' انتخاب نکردن تصویر، تصویر فعلی را نگه می‌دارد.' : ''}</p><p class="form-message" role="alert"></p><button class="primary" type="submit">ذخیره روی سرور</button></form>`;
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
  } else if (view === 'account') {
    const data = (await api.call('account')).account;
    html = `<h1>حساب مدیر</h1><form id="account-form" class="card"><p>نام کاربری: ${e(data.Username)}</p><label>ایمیل<input name="email" type="email" maxlength="100" required dir="ltr" value="${e(data.Email)}"></label><details><summary>تغییر رمز ورود</summary><label>رمز فعلی<input name="old_password" type="password" autocomplete="current-password"></label><label>رمز جدید<input name="new_password" type="password" autocomplete="new-password"></label><label>تکرار رمز جدید<input name="confirm_password" type="password" autocomplete="new-password"></label><p class="help">بعد از تغییر رمز، در پنل و اپ دوباره وارد شوید.</p></details><p class="form-message" role="alert"></p><button class="primary" type="submit">ذخیره مشخصات</button></form>`;
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
  if (after) after();
}
async function navigate(route) {
  if ((state.dirty || state.pending) && !window.confirm('تغییرات این صفحه ممکن است هنوز ثبت نشده باشد. صفحه را ترک می‌کنید؟')) return;
  notice();
  try { await render(route); state.dirty = false; state.pending = null; } catch (error) { if (error.status === 401) lock(error.message); notice(error.message, true); }
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
  const old = {'dashboard.php': 'home', 'story.php': 'posts', 'category.php': 'categories', 'admin.php': 'account', 'setting.php': 'account', 'add-menu.php': 'post-new', 'edit-menu.php': 'post', 'delete-menu.php': 'post', 'menu-detail.php': 'read', 'add-category.php': 'category-new', 'edit-category.php': 'category', 'delete-category.php': 'category', 'logout.php': 'logout'};
  const name = location.pathname.split('/').pop(); const id = new URLSearchParams(location.search).get('id');
  const view = old[name] || 'home'; return location.hash.slice(1) || view + (id && /^[1-9][0-9]*$/.test(id) ? `/${id}` : '');
}
if (api.token) {
  try { const me = await api.call('me'); showPanel(me.username); await render(initialRoute()); }
  catch (error) { lock(error.message); }
}
