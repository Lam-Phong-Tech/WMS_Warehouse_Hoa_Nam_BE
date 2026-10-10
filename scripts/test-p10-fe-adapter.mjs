import test from 'node:test';
import assert from 'node:assert/strict';
import {
  P10ContractError, parseProfileResponse, buildProfilePatch, parseSessionsResponse,
  revokeTargetId, parseAuthResultResponse, isCurrentResponse,
} from '../contracts/p10/fe-adapter-reference.mjs';

// Synthetic parser cases only. No login, request, seeding or storage mutation.
const actor = '11111111-1111-4111-8111-111111111111';
const family = '22222222-2222-4222-8222-222222222222';
const target = '33333333-3333-4333-8333-333333333333';
const other = '44444444-4444-4444-8444-444444444444';
const clone = (x) => JSON.parse(JSON.stringify(x));
const profile = () => ({status:200, etag:'"7"', body:{success:true, data:{
  user_id:actor, version:7, display_name:'Test name', phone:'0900000000',
  auth_session_id:family, active_warehouse_id:null,
  warehouse_context:{status:'REQUIRED',error_code:'WAREHOUSE_CONTEXT_REQUIRED'},
  roles:[], permissions:[], warehouse_scope_ids:[],
}, meta:{profile_contract:{version:'auth-profile-p10-v1',avatar:{supported:false}},
  security_contract:{version:'p11-existing-routes-v1'},request_id:other,timestamp:'2026-10-10T00:00:00Z'}}});
const sessions = () => ({status:200,body:{success:true,data:[{
  id:target,auth_session_id:other,session_id:other,is_current:false,can_revoke:true,
  device_label:null,location:null,user_agent:null,ip_address:null,
}],meta:{current_page:2,per_page:1,last_page:3,total:3,current_session_id:family,
  session_contract:{target_id:'id',target_id_kind:'REFRESH_TOKEN_ROW_UUID',count_unit:'ACTIVE_AUTH_SESSION_FAMILY'}},
  links:{next:'untrusted-not-followed-by-this-helper'}}});
const auth = (operation) => ({status:200,body:{success:true,data:null,meta:{auth_result:{
  state:'SUCCEEDED',durable_request_receipt:false,request_reconcile_supported:false,
  ...(operation==='LOGOUT' ? {scope:'CURRENT_AUTH_SESSION_FAMILY',business_drafts_deleted:false,work_shift_ended:false}
    : {relogin_required:true,session_policy:'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED'}),
}}}});
const throws = (fn) => assert.throws(fn, P10ContractError);

test('preserves full canonical profile/context/metadata and actual ETag', () => {
  const response=profile(); const parsed=parseProfileResponse(response);
  assert.strictEqual(parsed.profile,response.body.data); assert.strictEqual(parsed.meta,response.body.meta);
  assert.equal(parsed.etag,'"7"'); assert.equal(parsed.profile.active_warehouse_id,null);
});
test('null phone is canonical', () => {const r=profile();r.body.data.phone=null;assert.equal(parseProfileResponse(r).profile.phone,null);});
for (const etag of [undefined,null,'','7','W/"7"','"6"','"07"','*','"7", "8"']) {
  test(`rejects noncanonical ETag case ${String(etag)}`,()=>{const r=profile();r.etag=etag;throws(()=>parseProfileResponse(r));});
}
for (const field of ['version','phone','warehouse_context','auth_session_id']) {
  test(`no fallback for missing profile ${field}`,()=>{const r=profile();delete r.body.data[field];throws(()=>parseProfileResponse(r));});
}
test('does not treat non-200 or wrong contract as canonical profile',()=>{
  for(const status of [401,403,412,500,204]){const r=profile();r.status=status;throws(()=>parseProfileResponse(r));}
  const r=profile();r.body.meta.profile_contract.version='unknown';throws(()=>parseProfileResponse(r));
});
test('sparse UI name maps to trimmed display_name without phone',()=>{
  assert.deepEqual(buildProfilePatch(profile().body.data,{name:'  New name  '}),{display_name:'New name'});
});
test('explicit phone deletion sends null, not blank',()=>{
  for(const phone of ['',null,'   '])assert.deepEqual(buildProfilePatch(profile().body.data,{phone}),{phone:null});
});
test('undefined edit is omitted and cannot erase phone',()=>{
  assert.deepEqual(buildProfilePatch(profile().body.data,{name:'New',phone:undefined}),{display_name:'New'});
});
test('unchanged fields are not sent and empty update is blocked',()=>{
  throws(()=>buildProfilePatch(profile().body.data,{name:' Test name ',phone:'0900000000'}));
});
for (const edits of [{name:''},{name:null},{name:'x'.repeat(256)},{phone:900000000},{phone:'+84900000000'},{phone:'123'},
  {nickname:'x'},{permissions:['admin']},{avatar_url:'https://invalid.example'}]) {
  test(`rejects unsupported or invalid edit ${Object.keys(edits)[0]} ${JSON.stringify(edits).length}`,()=>throws(()=>buildProfilePatch(profile().body.data,edits)));
}
test('preserves sessions meta.total/current family/links and does not infer device',()=>{
  const r=sessions();const parsed=parseSessionsResponse(r);assert.strictEqual(parsed.meta,r.body.meta);
  assert.equal(parsed.meta.total,3);assert.equal(parsed.sessions.length,1);assert.equal(parsed.sessions[0].device_label,null);
  assert.strictEqual(parsed.links,r.body.links);
});
test('empty page is not proof total zero',()=>{const r=sessions();r.body.data=[];assert.equal(parseSessionsResponse(r).meta.total,3);});
test('missing total is not inferred from page length',()=>{const r=sessions();delete r.body.meta.total;throws(()=>parseSessionsResponse(r));});
test('current-family metadata can exist off-page',()=>{assert.equal(parseSessionsResponse(sessions()).meta.current_session_id,family);});
test('revoke target is row id, not stable family',()=>{const row=sessions().body.data[0];assert.equal(revokeTargetId(row),target);assert.notEqual(revokeTargetId(row),row.auth_session_id);});
test('current and readonly rows cannot be revoked',()=>{
  for(const change of [{is_current:true},{can_revoke:false},{can_revoke:undefined},{id:undefined}])throws(()=>revokeTargetId({...sessions().body.data[0],...change}));
});
test('conflicting session aliases or flags are rejected',()=>{
  const r=sessions();r.body.data[0].session_id=family;throws(()=>parseSessionsResponse(r));
  const s=sessions();s.body.data[0].is_current=true;throws(()=>parseSessionsResponse(s));
});
for(const operation of ['LOGOUT','CHANGE_PASSWORD']){
  test(`${operation} requires canonical auth_result`,()=>{const r=auth(operation);assert.strictEqual(parseAuthResultResponse(r,operation).meta,r.body.meta);});
  test(`${operation} data:null or success alone is insufficient`,()=>{
    const r=auth(operation);delete r.body.meta.auth_result;throws(()=>parseAuthResultResponse(r,operation));
  });
  test(`${operation} no receipt or error state may be invented`,()=>{
    for(const field of ['durable_request_receipt','request_reconcile_supported','state']){
      const r=auth(operation);delete r.body.meta.auth_result[field];throws(()=>parseAuthResultResponse(r,operation));
    }
  });
}
test('logout access-only scope is explicit and retained',()=>{const r=auth('LOGOUT');r.body.meta.auth_result.scope='CURRENT_ACCESS_TOKEN_ONLY';assert.equal(parseAuthResultResponse(r,'LOGOUT').authResult.scope,'CURRENT_ACCESS_TOKEN_ONLY');});
test('logout never reports end-shift or draft deletion',()=>{
  for(const field of ['work_shift_ended','business_drafts_deleted']){const r=auth('LOGOUT');r.body.meta.auth_result[field]=true;throws(()=>parseAuthResultResponse(r,'LOGOUT'));}
});
test('password success requires relogin and all-family policy',()=>{
  const r=auth('CHANGE_PASSWORD');r.body.meta.auth_result.relogin_required=false;throws(()=>parseAuthResultResponse(r,'CHANGE_PASSWORD'));
});
test('same caller/auth generation accepts only current request',()=>{
  const t={actorId:actor,authSessionId:family,authGeneration:2,requestSequence:3,caller:'PROFILE'};
  assert.equal(isCurrentResponse(t,clone(t)),true);
  for(const change of [{actorId:other},{authSessionId:other},{authGeneration:3},{requestSequence:4},{caller:'SESSIONS'},
    {authGeneration:undefined},{requestSequence:undefined}])assert.equal(isCurrentResponse(t,{...t,...change}),false);
});
test('access-only new login still needs a new local auth generation',()=>{
  const t={actorId:actor,authSessionId:null,authGeneration:2,requestSequence:3,caller:'PROFILE'};
  assert.equal(isCurrentResponse(t,{...t,authGeneration:3}),false);
  assert.equal(isCurrentResponse({},{}),false);
});
