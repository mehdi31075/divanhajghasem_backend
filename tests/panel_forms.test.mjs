import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import vm from 'node:vm';
import {JSDOM} from 'jsdom';
const php=process.env.DIVAN_TEST_PHP || 'php';
const html=execFileSync(php,['tests/render_panel.php'],{encoding:'utf8'});
const sources=await Promise.all(['app','client'].map(name=>fs.readFile(`public/assets/panel/${name}.js`,'utf8')));
const token='a'.repeat(64);
const tick=()=>new Promise(resolve=>setTimeout(resolve,10));
async function waitFor(fn){for(let i=0;i<100;i++){if(fn())return;await tick();}throw new Error('Timed out waiting for form state');}
async function fixture({rich=false}={}){
 const dom=new JSDOM(html,{url:'https://fixture.test/index.php',runScripts:'outside-only'});
 dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
 dom.window.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new dom.window.Event('close'));};
 const state={categories:[{cid:'61',category_name:'دسته اول',category_image:'one.png',author:'نویسنده',status:'1'}],pages:[{slug:"first-talk",title:"سخن اول",html_body:"<p>متن اولیه</p>",revision:1}],posts:[],videos:[],mutations:0,expired:false,loseNextResponse:false};
 dom.window.confirm=()=>true;dom.window.scrollTo=()=>{};
 dom.window.fetch=async(url,init)=>{
  assert.equal(init.credentials,'omit');const action=url.searchParams.get('action')||'';
  const fields=init.body ? Object.fromEntries(init.body.entries()) : {};
  const reply=(data,status=200)=>new Response(JSON.stringify(data),{status});
  if(action==='login'){state.expired=false;return fields.username==='wrong'?reply({ok:false},401):reply({ok:true,access_token:token,token_type:'Bearer'});}
  if(!action){const cat=url.searchParams.get('cat_id'),id=url.searchParams.get('nid'); const rows=cat||id?state.posts.filter(p=>cat?String(p.cat_id)===cat:String(p.nid)===id):state.categories;return reply(rows.length?{AndroidEbookApp:rows}:[]);}
  assert.equal(init.headers.Authorization,`Bearer ${token}`);
  if(state.expired)return reply({ok:false},401);
  if(action==='me')return reply({ok:true,username:'fixture-admin'});
  if(action==='logout')return reply({ok:true});
  if(action==='stats')return reply({ok:true,stats:{posts:state.posts.length,categories:state.categories.length}});
  if(action==='posts')return reply({ok:true,posts:[...state.posts].reverse(),total:state.posts.length,page:1});
  if(action==='pages')return reply({ok:true,pages:state.pages});
  if(action==='media_list')return reply({ok:true,videos:state.videos});
  if(action==='page_update' && Number(fields.revision)!==state.pages.find(p=>p.slug===fields.slug).revision)return reply({ok:false,message:'صفحه روی سرور تغییر کرده است'},409);
  if(action==='account')return reply({ok:true,account:{Username:'fixture-admin',Email:'fixture@example.test'}});
  state.mutations++;
  if(action==='page_update'){const p=state.pages.find(p=>p.slug===fields.slug);Object.assign(p,{title:fields.title,html_body:fields.html_body,revision:p.revision+1});}
  else if(action==='create'){state.posts.push({...fields,nid:String(state.mutations),cat_id:fields.cid});}
  else if(action==='update'){const p=state.posts.find(p=>p.nid===fields.id);Object.assign(p,fields,{cat_id:fields.cid});}
  else if(action==='delete'){state.posts=state.posts.filter(p=>p.nid!==fields.id);}
  else if(action==='category_update'){Object.assign(state.categories.find(c=>c.cid===fields.id),{category_name:fields.category_name,author:fields.author});}
  else throw new Error(`Unhandled action ${action}`);
  if(state.loseNextResponse){state.loseNextResponse=false;throw new Error('response lost after commit');}
  return reply({ok:true});
 };
 const context=dom.getInternalVMContext();
 if(rich){
  vm.runInContext(await fs.readFile('public/assets/purify/purify.min.js','utf8'),context);
  dom.window.ResizeObserver=class {observe(){} unobserve(){} disconnect(){}};
  dom.window.HTMLCanvasElement.prototype.getContext=()=>null;
  dom.window.Range.prototype.getClientRects=()=>[];
  dom.window.Range.prototype.getBoundingClientRect=()=>({left:0,right:0,top:0,bottom:0,width:0,height:0});
  dom.window.HTMLElement.prototype.scrollIntoView=()=>{};
  vm.runInContext(await fs.readFile('public/assets/ckeditor/ckeditor5.umd.js','utf8'),context);
  vm.runInContext(await fs.readFile('public/assets/ckeditor/fa.umd.js','utf8'),context);
  const create=dom.window.CKEDITOR.ClassicEditor.create.bind(dom.window.CKEDITOR.ClassicEditor);
  dom.window.CKEDITOR.ClassicEditor.create=async(...args)=>{const editor=await create(...args); state.richEditor=editor; return editor;};
 }
 const client=new vm.SourceTextModule(sources[1],{context,identifier:'client.js'});
 const app=new vm.SourceTextModule(sources[0],{context,identifier:'app.js'});
 await app.link(()=>client);await app.evaluate();
 const doc=dom.window.document;
 const input=(selector,value)=>{const node=doc.querySelector(selector);assert.ok(node,selector);node.value=value;node.dispatchEvent(new dom.window.Event('input',{bubbles:true}));};
 const submit=selector=>doc.querySelector(selector).dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));
 const click=selector=>{const node=doc.querySelector(selector);assert.ok(node,selector);node.click();};
 const login=async(username='fixture-admin')=>{input('#login-form [name=username]',username);input('#login-form [name=password]','fixture-password');submit('#login-form');await waitFor(()=>!doc.querySelector('#panel').hidden);};
 return {dom,state,doc,input,submit,click,login};
}
test('real panel forms login, create, edit and delete using tokens',async()=>{
 const f=await fixture();try{
  f.input('#login-form [name=username]','wrong');f.input('#login-form [name=password]','wrong');f.submit('#login-form');await waitFor(()=>f.doc.querySelector('#login-message').textContent);assert.equal(f.doc.querySelector('#panel').hidden,true);
  await f.login();await waitFor(()=>f.doc.querySelector('[data-route="post-new"]'));
  f.click('[data-route="post-new"]');await waitFor(()=>f.doc.querySelector('#post-form'));
  f.input('[name=news_heading]','عنوان');f.input('[name=news_date]','زیرعنوان');f.input('#body','<p><strong>متن فارسی</strong></p>');f.submit('#post-form');
  await waitFor(()=>f.doc.querySelector('[data-route="post/1"]'));
  assert.equal(f.state.posts.length,1);assert.equal(f.state.posts[0].news_description,'<p><strong>متن فارسی</strong></p>');
  f.click('[data-route="post/1"]');await waitFor(()=>f.doc.querySelector('#post-form'));f.input('[name=news_heading]','عنوان تازه');f.submit('#post-form');await waitFor(()=>!f.doc.querySelector('#post-form'));
  assert.equal(f.state.posts[0].news_heading,'عنوان تازه');assert.equal(f.state.posts[0].news_description,'<p><strong>متن فارسی</strong></p>');
  f.click('[data-delete="post"]');await waitFor(()=>f.state.posts.length===0 && f.doc.querySelector('.empty'));assert.equal(f.state.mutations,3);
  f.click('#logout');await waitFor(()=>!f.doc.querySelector('#login').hidden);assert.equal(f.dom.window.sessionStorage.getItem('divan_admin_token'),null);
 }finally{f.dom.window.close();}
});
test('category form preserves an existing image and escapes server names',async()=>{
 const f=await fixture();try{await f.login();await waitFor(()=>f.doc.querySelector('[data-route="categories"]'));f.click('[data-route="categories"]');await waitFor(()=>f.doc.querySelector('[data-route="category/61"]'));
  f.click('[data-route="category/61"]');await waitFor(()=>f.doc.querySelector('#category-form'));const malicious='<img src=x onerror="window.pwned=1">';f.input('[name=category_name]',malicious);f.submit('#category-form');
  await waitFor(()=>!f.doc.querySelector('#category-form'));assert.equal(f.state.categories[0].category_image,'one.png');assert.equal(f.doc.querySelector('.category-card h2').textContent,malicious);assert.equal(f.dom.window.pwned,undefined);
 }finally{f.dom.window.close();}
});
test('expired login retains the unsaved form and resumes it after reauthentication',async()=>{
 const f=await fixture();try{await f.login();await waitFor(()=>f.doc.querySelector('[data-route="post-new"]'));f.click('[data-route="post-new"]');await waitFor(()=>f.doc.querySelector('#post-form'));
  f.input('[name=news_heading]','پیش نویس');f.input('[name=news_date]','زیرعنوان');f.input('#body','متن محفوظ');f.state.expired=true;f.submit('#post-form');await waitFor(()=>!f.doc.querySelector('#login').hidden);assert.equal(f.state.mutations,0);
  await f.login();assert.equal(f.doc.querySelector('#body').value,'متن محفوظ');f.submit('#post-form');await waitFor(()=>f.state.posts.length===1 && !f.doc.querySelector('#post-form'));assert.equal(f.state.mutations,1);
 }finally{f.dom.window.close();}
});
test('lost create response changes the button to read-only reconciliation',async()=>{
 const f=await fixture();try{await f.login();await waitFor(()=>f.doc.querySelector('[data-route="post-new"]'));f.click('[data-route="post-new"]');await waitFor(()=>f.doc.querySelector('#post-form'));
  f.input('[name=news_heading]','پیش نویس');f.input('[name=news_date]','زیرعنوان');f.input('#body','متن محفوظ');f.state.loseNextResponse=true;f.submit('#post-form');await waitFor(()=>f.doc.querySelector('#post-form button[type=submit]').textContent==='بررسی نتیجه');
  assert.equal(f.state.mutations,1);f.submit('#post-form');await waitFor(()=>!f.doc.querySelector('#post-form'));assert.equal(f.state.posts.length,1);assert.equal(f.state.mutations,1);
 }finally{f.dom.window.close();}
});

test('real CKEditor preserves untouched legacy HTML and sanitizes edited exports',async()=>{
 const f=await fixture({rich:true});try{
  const original='<p dir="rtl" style="text-align:center"><strong>متن قدیمی</strong></p><table><tbody><tr><td>جدول قدیمی</td></tr></tbody></table>';
  f.state.posts.push({nid:'9',cat_id:'61',news_heading:'عنوان قبلی',news_date:'زیرعنوان',news_description:original});
  await f.login();await waitFor(()=>f.doc.querySelector('[data-route="posts"]'));
  f.click('[data-route="posts"]');await waitFor(()=>f.doc.querySelector('[data-route="post/9"]'));
  f.click('[data-route="post/9"]');await waitFor(()=>f.doc.querySelector('.ck-editor__editable') && !f.doc.querySelector('#post-form button[type=submit]').disabled);
  f.input('[name=news_heading]','عنوان تازه');f.submit('#post-form');await waitFor(()=>!f.doc.querySelector('#post-form'));
  assert.equal(f.state.posts[0].news_description,original);
  f.click('[data-route="post/9"]');await waitFor(()=>f.doc.querySelector('.ck-editor__editable') && !f.doc.querySelector('#post-form button[type=submit]').disabled);
  const editor=f.state.richEditor;
  assert.ok(editor.plugins.has('SourceEditing'));
  assert.ok(editor.plugins.has('Table'));
  assert.ok(editor.plugins.has('ImageInsertViaUrl'));
  editor.setData('<p>تغییر</p>');
  editor.getData=()=>'<p>متن ویرایش‌شده</p><img src="x" onerror="window.pwned=1"><script>window.pwned=1</script>';
  f.submit('#post-form');await waitFor(()=>!f.doc.querySelector('#post-form'));
  assert.equal(f.state.posts[0].news_description,'<p>متن ویرایش‌شده</p><img src="x">');
  assert.equal(f.dom.window.pwned,undefined);
 }finally{f.dom.window.close();}
});

test('CKEditor source mode saves table formatting and keeps unsaved source through expired login',async()=>{
 const f=await fixture({rich:true});try{
  await f.login();await waitFor(()=>f.doc.querySelector('[data-route="post-new"]'));
  f.click('[data-route="post-new"]');await waitFor(()=>f.doc.querySelector('.ck-editor__editable') && !f.doc.querySelector('#post-form button[type=submit]').disabled);
  f.input('[name=news_heading]','جدول');f.input('[name=news_date]','زیرعنوان');
  const editor=f.state.richEditor;const source=editor.plugins.get('SourceEditing');
  source.isSourceEditingMode=true;
  f.input('.ck-source-editing-area textarea','<p style="color:red">متن فارسی</p><table><tbody><tr><td>خانه جدول</td></tr></tbody></table>');
  f.state.expired=true;f.submit('#post-form');await waitFor(()=>!f.doc.querySelector('#login').hidden);
  assert.equal(f.state.mutations,0);await f.login();
  assert.ok(f.doc.querySelector('.ck-source-editing-area textarea').value.includes('خانه جدول'));
  f.submit('#post-form');await waitFor(()=>f.state.posts.length===1 && !f.doc.querySelector('#post-form'));
  const body=f.state.posts[0].news_description;
  assert.match(body,/<table/);assert.match(body,/خانه جدول/);assert.match(body,/color:red/);
 }finally{f.dom.window.close();}
});

test('post editor inserts an uploaded host video from its media library',async()=>{
 const f=await fixture({rich:true});try{
  f.state.videos=[{name:'clip.mp4',url:'/upload/news-media/clip.mp4',mime_type:'video/mp4',created_at:'2026-10-08T10:00:00Z'}];
  await f.login();await waitFor(()=>f.doc.querySelector('[data-route="post-new"]'));
  f.click('[data-route="post-new"]');await waitFor(()=>f.doc.querySelector('.ck-editor__editable') && !f.doc.querySelector('#post-form button[type=submit]').disabled);
  const videoAction=f.doc.querySelector('.divan-video-button');assert.ok(videoAction);assert.equal(videoAction.closest('.ck-toolbar__items')!==null,true);assert.equal(videoAction.getAttribute('aria-label'),'بارگذاری و درج ویدیو از هاست');
  f.click('.divan-video-button');await waitFor(()=>f.doc.querySelector('[data-insert-video]'));
  f.click('[data-insert-video]');await waitFor(()=>!f.doc.querySelector('.media-dialog'));
  assert.match(f.state.richEditor.getData(),/<video[^>]*src="https:\/\/divanhajghasem\.ir\/upload\/news-media\/clip\.mp4"/);
 }finally{f.dom.window.close();}
});

test('page editor uses real CKEditor, preserves original HTML and reconciles a lost response',async()=>{
 const f=await fixture({rich:true});try{
  await f.login();await waitFor(()=>f.doc.querySelector('nav a[href="#pages"]'));f.click('nav a[href="#pages"]');await waitFor(()=>f.doc.querySelector('[data-route="page/first-talk"]'));
  f.click('[data-route="page/first-talk"]');await waitFor(()=>f.doc.querySelector('.ck-editor__editable') && !f.doc.querySelector('#page-form button[type=submit]').disabled);
  f.input('[name=title]','عنوان تازه');f.state.loseNextResponse=true;f.submit('#page-form');
  await waitFor(()=>f.doc.querySelector('#page-form button[type=submit]').textContent==='بررسی نتیجه');
  assert.equal(f.state.pages[0].html_body,'<p>متن اولیه</p>');assert.equal(f.state.pages[0].revision,2);
  assert.equal(f.state.richEditor.isReadOnly,true);f.submit('#page-form');await waitFor(()=>!f.doc.querySelector('#page-form'));assert.equal(f.state.mutations,1);
  f.click('[data-route="page/first-talk"]');await waitFor(()=>f.doc.querySelector('.ck-editor__editable') && !f.doc.querySelector('#page-form button[type=submit]').disabled);
  f.state.richEditor.setData('<p><strong>متن تازه</strong></p>');f.state.pages[0].revision++;
  f.submit('#page-form');await waitFor(()=>f.doc.querySelector('#page-form .form-message').textContent.includes('تغییر'));
  assert.equal(f.state.richEditor.getData(),'<p><strong>متن تازه</strong></p>');assert.equal(f.state.richEditor.isReadOnly,false);assert.equal(f.state.pages[0].html_body,'<p>متن اولیه</p>');
 }finally{f.dom.window.close();}
});
