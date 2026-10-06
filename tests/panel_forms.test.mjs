import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import vm from 'node:vm';
import {JSDOM} from 'jsdom';
const php=process.env.DIVAN_TEST_PHP || 'php';
const html=execFileSync(php,['-r', '$_SERVER["REQUEST_METHOD"]="GET";require "includes/panel.php";'],{encoding:'utf8'});
const sources=await Promise.all(['app','client'].map(name=>fs.readFile(`assets/panel/${name}.js`,'utf8')));
const token='a'.repeat(64);
const tick=()=>new Promise(resolve=>setTimeout(resolve,10));
async function waitFor(fn){for(let i=0;i<100;i++){if(fn())return;await tick();}throw new Error('Timed out waiting for form state');}
async function fixture(){
 const dom=new JSDOM(html,{url:'https://fixture.test/index.php',runScripts:'outside-only'});
 const state={categories:[{cid:'61',category_name:'دسته اول',category_image:'one.png',author:'نویسنده',status:'1'}],posts:[],mutations:0,expired:false,loseNextResponse:false};
 dom.window.confirm=()=>true;
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
  if(action==='account')return reply({ok:true,account:{Username:'fixture-admin',Email:'fixture@example.test'}});
  state.mutations++;
  if(action==='create'){state.posts.push({...fields,nid:String(state.mutations),cat_id:fields.cid});}
  else if(action==='update'){const p=state.posts.find(p=>p.nid===fields.id);Object.assign(p,fields,{cat_id:fields.cid});}
  else if(action==='delete'){state.posts=state.posts.filter(p=>p.nid!==fields.id);}
  else if(action==='category_update'){Object.assign(state.categories.find(c=>c.cid===fields.id),{category_name:fields.category_name,author:fields.author});}
  else throw new Error(`Unhandled action ${action}`);
  if(state.loseNextResponse){state.loseNextResponse=false;throw new Error('response lost after commit');}
  return reply({ok:true});
 };
 const context=dom.getInternalVMContext();
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
