'use strict';
// Pure offline tests: no credentials, HTTP calls, database, files/chips or owner writes.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const collection = JSON.parse(fs.readFileSync(path.join(__dirname, '../../contracts/p18/P18-owner-read.postman_collection.json'), 'utf8'));
const pre = collection.event.find(e => e.listen === 'prerequest').script.exec.join('\n');
const post = collection.event.find(e => e.listen === 'test').script.exec.join('\n');
const trace = '00000000-0000-4000-8000-000000000099';
const uuid = n => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
function context(name = 'INBOUND_RECONCILE', changes = {}) {
  const vars = {api_origin:'https://khohoanamdev.lptech.info.vn', approved_api_origin:'https://khohoanamdev.lptech.info.vn',
    read_id:name, live_read_authorized:'true', owner_gate_authorized:'true', access_token:'isolated-placeholder-not-a-token',
    parent_type:name.split('_')[0], parent_id:uuid(1), warehouse_id:uuid(2), actor_id:uuid(3), auth_session_id:uuid(4),
    current_auth_session_id:uuid(4), operation_key:uuid(5), file_sha256:'a'.repeat(64), original_upload_contract:'owner-attachment-upload-v1',
    expected_http_status:'200', expected_error_code:'', expected_outcome:'SUCCEEDED', ...changes};
  const item = collection.item.find(i => i.name === name) || collection.item[0];
  const request = {method:'GET', url: {toString: () => item.request.url}};
  let skipped = 0;
  const pm = {info:{requestName:name}, request,
    variables:{get:k=>vars[k], set:(k,v)=>{vars[k]=v;}, replaceIn:s=>s.replace(/\{\{([^{}]+)\}\}/g, (_,k)=>k==='$guid'?trace:String(vars[k]??''))},
    execution:{skipRequest:()=>{skipped++;}}};
  return {pm, vars, request, skipped:()=>skipped};
}
function permit(ctx) { vm.runInNewContext(pre, {pm:ctx.pm}, {timeout:1000}); assert.equal(ctx.skipped(),0); assert.equal(ctx.vars.p18_read_trace,trace); }
function block(ctx) { assert.throws(()=>vm.runInNewContext(pre,{pm:ctx.pm},{timeout:1000}),/P18 read blocked/); assert.ok(ctx.skipped()>0); assert.equal(ctx.vars.p18_read_trace,undefined); }
for (const i of collection.item) test(`explicitly approved ${i.name} is a single GET`, ()=>permit(context(i.name)));
for (const [name, changes] of [
  ['read gate OFF',{live_read_authorized:'false'}], ['owner gate OFF',{owner_gate_authorized:'false'}],
  ['unselected request',{read_id:'OTHER'}], ['foreign origin',{api_origin:'https://example.invalid'}],
  ['different approved host',{approved_api_origin:'https://example.invalid'}], ['plain HTTP',{api_origin:'http://khohoanamdev.lptech.info.vn'}],
  ['missing access token',{access_token:''}], ['header injection',{access_token:'abc\r\nx:y'}],
  ['missing expected status',{expected_http_status:''}], ['bad status',{expected_http_status:'299'}],
  ['unverified 403',{expected_http_status:'403'}], ['wrong owner',{parent_type:'WARRANTY'}],
  ['new login',{current_auth_session_id:uuid(8)}], ['legacy upload',{original_upload_contract:''}],
  ['non-UUID key',{operation_key:'PX-123'}], ['uppercase hash',{file_sha256:'A'.repeat(64)}],
  ['wrong hash length',{file_sha256:'a'}], ['fixture state',{expected_outcome:'NOT_RECORDED'}],
  ['trace confused with key',{operation_key:trace}], ['traversal parent',{parent_id:'../outbound/record'}]
]) test(`preflight blocks ${name}`,()=>block(context('INBOUND_RECONCILE',changes)));
for (const key of ['parent_id','warehouse_id','actor_id','auth_session_id','current_auth_session_id'])
  test(`preflight requires ${key}`,()=>block(context('INBOUND_RECONCILE',{[key]:''})));
for (const method of ['POST','PUT','PATCH','DELETE']) test(`preflight blocks ${method}`,()=>{const c=context();c.request.method=method;block(c);});
test('GET body is forbidden',()=>{const c=context();c.request.body={mode:'raw',raw:'{}'};block(c);});
for (const suffix of ['&warehouse_id='+uuid(9), '&file_sha256='+('b'.repeat(64)), '/cancel', '#fragment'])
  test('preflight rejects altered URL '+suffix.slice(0,20),()=>{const c=context();const u=c.request.url.toString();c.request.url.toString=()=>u+suffix;block(c);});
test('unknown request never executes',()=>block(context('UPLOAD')));
test('explicit owner 403 can be checked without inferring success',()=>permit(context('INBOUND_RECONCILE',{expected_http_status:'403',expected_error_code:'ACCESS_DENIED',expected_outcome:''})));
function response(ctx, outcome = 'SUCCEEDED') {
  const v=ctx.vars;
  const receipt={contract:'owner-attachment-upload-v1',command:'UPLOAD',request_key:v.operation_key,actor_id:v.actor_id,auth_session_id:v.auth_session_id,
    warehouse_id:v.warehouse_id,parent_type:v.parent_type,parent_id:v.parent_id,file_sha256:v.file_sha256,attachment_id:uuid(6),outcome,
    http_status:outcome==='SUCCEEDED'?201:422,attachment_status_at_completion:outcome==='SUCCEEDED'?'ACTIVE':'QUARANTINED',attachment_version_at_completion:1};
  return {success:true,meta:{contract:'owner-attachment-upload-v1',request_id:trace},data:{outcome,request_state:outcome==='UNKNOWN'?'PENDING':outcome,
    request_key:v.operation_key,parent_type:v.parent_type,parent_id:v.parent_id,warehouse_id:v.warehouse_id,auth_session_id:v.auth_session_id,file_sha256:v.file_sha256,
    receipt:outcome==='UNKNOWN'?null:receipt,current_attachment:{attachment_id:uuid(6),status:'ACTIVE',version:3,owner_download_required:true},
    retry_policy:outcome==='UNKNOWN'?'CHECK_ONLY':'NONE',cancel_supported:false}};
}
function check(ctx, body, status=200, mime='application/json') {
  ctx.pm.response={code:status,headers:{get:()=>mime},json:()=>body};
  ctx.pm.test=(_name, fn)=>fn();
  ctx.pm.expect=value=>({to:{eql:expected=>assert.deepEqual(JSON.parse(JSON.stringify(value)),JSON.parse(JSON.stringify(expected))),
    include:expected=>assert.ok(value.includes(expected))}});
  vm.runInNewContext(post,{pm:ctx.pm},{timeout:1000});
}
for (const outcome of ['SUCCEEDED','REJECTED','UNKNOWN']) test(`valid ${outcome} response preserves native semantics`,()=>{const c=context('INBOUND_RECONCILE',{expected_outcome:outcome});check(c,response(c,outcome));});
test('historical receipt ACTIVE and current DELETED are not conflated',()=>{const c=context();const b=response(c);b.data.current_attachment.status='DELETED';check(c,b);});
test('UNKNOWN may have no current attachment',()=>{const c=context('INBOUND_RECONCILE',{expected_outcome:'UNKNOWN'});const b=response(c,'UNKNOWN');b.data.current_attachment=null;check(c,b);});
test('REJECTED can have no attachment and is not universal rollback proof',()=>{const c=context('INBOUND_RECONCILE',{expected_outcome:'REJECTED'});const b=response(c,'REJECTED');b.data.current_attachment=null;b.data.receipt.attachment_id=null;b.data.receipt.attachment_status_at_completion=null;check(c,b);});
for (const key of ['parent_id','actor_id','auth_session_id','request_key','file_sha256'])
  test(`response rejects substituted receipt ${key}`,()=>{const c=context();const b=response(c);b.data.receipt[key]='substituted';assert.throws(()=>check(c,b));});
test('response rejects attachment ID unrelated to receipt',()=>{const c=context();const b=response(c);b.data.current_attachment.attachment_id=uuid(9);assert.throws(()=>check(c,b));});
test('response rejects fabricated UNKNOWN success receipt',()=>{const c=context('INBOUND_RECONCILE',{expected_outcome:'UNKNOWN'});const b=response(c,'UNKNOWN');b.data.receipt={outcome:'SUCCEEDED'};assert.throws(()=>check(c,b));});
test('proxy HTML 403 cannot pass owner error validation',()=>{const c=context('INBOUND_RECONCILE',{expected_http_status:'403',expected_error_code:'ACCESS_DENIED'});assert.throws(()=>check(c,{},403,'text/html'));});
test('collection has no mutation, binary download, persisted response or credential',()=>{
  assert.equal(collection.item.length,7);assert.equal(collection.protocolProfileBehavior.followRedirects,false);
  assert.equal(collection.auth.bearer[0].value,'{{access_token}}');
  for(const i of collection.item){assert.equal(i.request.method,'GET');assert.equal(i.request.body,undefined);assert.ok(!i.request.url.includes('/download'));assert.deepEqual(i.request.header.map(h=>h.key),['Accept','X-Request-ID']);}
  assert.ok(!collection.variable.some(v=>v.key==='access_token'));
  for(const v of collection.variable) assert.ok(v.value===''||v.value==='false');
  assert.ok(!/pm\.(sendRequest|environment\.set|collectionVariables\.set)|console\./.test(pre+'\n'+post));
});
