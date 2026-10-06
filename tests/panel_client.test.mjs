import test from 'node:test';
import assert from 'node:assert/strict';
import {TokenApi, ApiError, escapeHtml, imageUrl, matchingWrite} from '../public/assets/panel/client.js';
const token = 'a'.repeat(64);
const json = (data, status = 200) => new Response(JSON.stringify(data), {status, headers: {'Content-Type':'application/json'}});
function storage() { const entries = new Map(); return {entries, getItem: k => entries.get(k), setItem: (k,v) => entries.set(k,v), removeItem: k => entries.delete(k)}; }
test('login verifies the token and every panel request omits cookies', async () => {
  const requests = []; const session = storage();
  const api = new TokenApi('https://fixture.test/mobile-api.php', {storage: session, fetcher: async (url, init) => {
    requests.push({url, init}); assert.equal(init.credentials,'omit');
    if(url.searchParams.get('action')==='login') { assert.equal(init.headers.Authorization,undefined); assert.equal(session.entries.size,0); return json({ok:true,access_token:token,token_type:'Bearer'}); }
    assert.equal(init.headers.Authorization,`Bearer ${token}`); return json({ok:true,username:'fixture-admin'});
  }});
  await api.login('fixture-admin','fixture-password');
  assert.equal(requests[0].init.body.get('password'),'fixture-password');
  assert.equal(session.entries.get('divan_admin_token'),token);
  assert.deepEqual([...session.entries.keys()],['divan_admin_token']);
  await api.call('create',{method:'POST',fields:{news_heading:'متن'}});
  await api.logout(); assert.equal(api.token,''); assert.equal(session.entries.size,0);
});
test('wrong credentials and invalid tokens never enable or persist login', async () => {
  for(const response of [json({ok:false},401),json({ok:true,access_token:'bad',token_type:'Bearer'})]) {
    const session=storage(); const api=new TokenApi('https://fixture.test/mobile-api.php',{storage:session,fetcher:async()=>response});
    await assert.rejects(api.login('wrong','wrong'),ApiError); assert.equal(api.token,''); assert.equal(session.entries.size,0);
  }
});
test('expired token clears access and a cookie cannot substitute for it', async () => {
  const session=storage();session.setItem('divan_admin_token',token);
  const api=new TokenApi('https://fixture.test/mobile-api.php',{storage:session,fetcher:async()=>json({ok:false},401)});
  await assert.rejects(api.call('me'),e=>e.status===401);assert.equal(api.token,'');assert.equal(session.entries.size,0);
});
test('late expiration from an old token cannot clear a newer login', async () => {
  let finish;const api=new TokenApi('https://fixture.test/mobile-api.php',{fetcher:()=>new Promise(resolve=>{finish=resolve;})});
  api.setToken(token);const pending=api.call('me');api.setToken('b'.repeat(64));finish(json({ok:false},401));
  await assert.rejects(pending,e=>e.status===409);assert.equal(api.token,'b'.repeat(64));
});
test('uncertain writes differ from definite rejections and are not automatically repeated', async () => {
  let calls=0;const api=new TokenApi('https://fixture.test/mobile-api.php',{fetcher:async()=>{calls++;throw new Error('connection lost');}});
  await assert.rejects(api.call('create',{method:'POST'}),e=>e.uncertain===true);assert.equal(calls,1);
  const rejected=new TokenApi('https://fixture.test/mobile-api.php',{fetcher:async()=>json({ok:false,message:'invalid'},422)});
  await assert.rejects(rejected.call('create',{method:'POST'}),e=>e.uncertain===false && e.status===422);
});
test('multipart sends Bearer and lets the browser set the upload boundary', async () => {
  const fields=new FormData();fields.append('category_name','دسته');fields.append('category_image',new Blob(['fixture'],{type:'image/png'}),'image.png');
  const api=new TokenApi('https://fixture.test/mobile-api.php',{fetcher:async(url,init)=>{
    assert.equal(init.body,fields);assert.equal(init.headers.Authorization,`Bearer ${token}`);assert.equal(init.headers['Content-Type'],undefined);return json({ok:true});}});
  api.setToken(token);await api.call('category_create',{method:'POST',fields});
});
test('anonymous public read contract and safe server text rendering', async () => {
  const api=new TokenApi('https://fixture.test/mobile-api.php',{fetcher:async(url,init)=>{assert.equal(init.headers.Authorization,undefined);return json([]);}});
  assert.deepEqual(await api.call(''),[]);
  assert.equal(escapeHtml('<img onerror="x">'),'&lt;img onerror=&quot;x&quot;&gt;');
  assert.equal(imageUrl('image.png',api.endpoint),'https://fixture.test/upload/category/image.png');
  assert.equal(imageUrl('javascript:alert(1)',api.endpoint),'');
  assert.equal(imageUrl('data:text/html,test',api.endpoint),'');
});
test('reconciliation matches a unique new server post and excludes old duplicates',()=>{
  const target={cid:'61',news_heading:'عنوان',news_date:'زیرعنوان',news_description:'<p>متن</p>'};
  const rows=[{...target,cat_id:'61',nid:'1'},{...target,cat_id:'61',nid:'2'}];
  assert.deepEqual(matchingWrite(rows,target,['1']).map(r=>r.nid),['2']);
});
