const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const c = JSON.parse(fs.readFileSync(path.join(root, 'contracts/p16/P16-documents-read.postman_collection.json')));
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'contracts/p16/owner-read-v1.json')));
const pre = c.event.find(e => e.listen === 'prerequest').script.exec.join('\n');
const post = c.event.find(e => e.listen === 'test').script.exec.join('\n');
const U = '11111111-1111-4111-8111-111111111111';
const T = '22222222-2222-4222-8222-222222222222';
function run(id = 'P12_INBOUND_LIST', overrides = {}, requestOverrides = {}) {
  const vars = {api_origin:'https://approved.invalid', approved_api_origin:'https://approved.invalid', read_id:id,
    live_read_authorized:'true',owner_gate_authorized:'true',access_token:'LOCAL-TEST-NO-NETWORK',actor_id:U,
    auth_session_id:U,current_auth_session_id:U,warehouse_id:U,expected_http_status:'200',expected_total:'0',filters_json:'{}', ...overrides};
  const item = c.item.find(i => i.name === id);
  const request = {method:'GET',url:{toString:()=>item ? item.request.url : '{{api_origin}}/api/v1/p16/all{{p16_query}}'},...requestOverrides};
  const replaceIn = input => input.replace(/\{\{([^}]+)\}\}/g, (_, key) => key === '$guid' ? T : String(vars[key] ?? '{{'+key+'}}'));
  let skipped = false;
  const pm = {info:{requestName:id},request,variables:{get:k=>vars[k],set:(k,value)=>{vars[k]=value;},replaceIn},execution:{skipRequest:()=>{skipped=true;}}};
  let error = null;
  try { vm.runInNewContext(pre, {pm}, {timeout:1000}); } catch(e) { error=e; }
  return {skipped,error,vars};
}
test('both decoded Postman scripts compile',()=>{ new vm.Script(pre); new vm.Script(post); });
for (const id of Object.keys(manifest.reads)) {
  test('permits one explicitly authorized GET '+id,()=>{const r=run(id);assert.equal(r.error,null);assert.equal(r.skipped,false);});
  test('skips unselected GET '+id,()=>{const r=run(id,{read_id:''});assert.equal(r.skipped,true);assert.equal(r.vars.p16_trace,undefined);});
}
for (const [label, overrides] of Object.entries({
  'without live authorization':{live_read_authorized:'false'},'pending owner':{owner_gate_authorized:'false'},
  'foreign origin':{api_origin:'https://foreign.invalid'},'http origin':{api_origin:'http://approved.invalid',approved_api_origin:'http://approved.invalid'},
  'origin path injection':{api_origin:'https://approved.invalid/path',approved_api_origin:'https://approved.invalid/path'},
  'missing token':{access_token:''},'session changed':{current_auth_session_id:T},'display number as warehouse':{warehouse_id:'KHO-HN'},
  'missing expected total':{expected_total:''},'unsafe expected total':{expected_total:'9007199254740999'},
  'proxy diagnosis':{expected_http_status:'403',expected_error_code:''},'malformed filter JSON':{filters_json:'{'},
  'array filters':{filters_json:'[]'},'foreign warehouse filter':{filters_json:JSON.stringify({warehouse_id:T})},
  'unknown filter':{filters_json:'{"foo":"bar"}'},'cursor on P12':{filters_json:'{"cursor":"x"}'},
  'page zero':{filters_json:'{"page":0}'},'oversized P12 page':{filters_json:'{"per_page":201}'},
  'invalid date':{filters_json:'{"date_from":"2026-02-30"}'},'reverse dates':{filters_json:'{"date_from":"2026-10-09","date_to":"2026-10-08"}'},
  'unapproved timezone':{filters_json:'{"timezone":"Invalid/Zone"}'},'new status':{filters_json:'{"status":"CONFIRMED"}'},
  'array keyword':{filters_json:'{"keyword":["x"]}'}
})) test('blocks '+label,()=>{const r=run('P12_INBOUND_LIST',overrides);assert.equal(r.skipped,true);});
for(const method of ['POST','PUT','PATCH','DELETE']) test('blocks '+method,()=>assert.equal(run('P12_INBOUND_LIST',{}, {method}).skipped,true));
test('blocks a GET body',()=>assert.equal(run('P12_INBOUND_LIST',{}, {body:{raw:'{}'}}).skipped,true));
test('blocks altered endpoint',()=>assert.equal(run('P12_INBOUND_LIST',{}, {url:{toString:()=> '{{api_origin}}/api/v1/p16/retry{{p16_query}}'}}).skipped,true));
test('no invented All endpoint',()=>assert.equal(run('ALL').skipped,true));
for(const filters of [{date_from:'2026-10-08'}, {customer_name:'x'}, {keyword:'x'}, {page:1,cursor:'x'}, {per_page:101}, {status:'SCANNING'}]) {
  test('P09 blocks unsupported/conflicting '+JSON.stringify(filters),()=>assert.equal(run('P09_WARRANTY_LIST',{filters_json:JSON.stringify(filters)}).skipped,true));
}
for(const [id,filters] of [
  ['P12_INBOUND_LIST',{keyword:'literal % _ &? x',page:2,per_page:200,date_from:'2026-10-08',date_to:'2026-10-09',timezone:'Asia/Ho_Chi_Minh',sort_order:'asc'}],
  ['P12_INBOUND_LIST',{status:'WAITING_APPROVAL'}],['P12_OUTBOUND_LIST',{status:'SCANNING'}],
  ['P09_WARRANTY_LIST',{search:'serial',status:'ALL',sort:'created_at_asc',per_page:100}],['P09_WARRANTY_LIST',{cursor:'opaque+cursor/=value',per_page:1}]
]) test('preserves supported owner query '+id+JSON.stringify(filters),()=>{const r=run(id,{filters_json:JSON.stringify(filters)});assert.equal(r.skipped,false);assert.equal(r.error,null);const q=new URLSearchParams(r.vars.p16_query);for(const [k,v] of Object.entries(filters))assert.equal(q.get(k),String(v));});
test('outbound cannot borrow inbound waiting-approval alias',()=>assert.equal(run('P12_OUTBOUND_LIST',{filters_json:'{"status":"WAITING_APPROVAL"}'}).skipped,true));
test('collection has no writer, fallback, aggregate, secrets or redirect',()=>{
 assert.deepEqual(manifest.methods,['GET']);assert.deepEqual(manifest.new_api_routes,[]);assert.equal(manifest.aggregate.path,null);assert.equal(manifest.aggregate.enabled,false);
 assert.equal(c.item.length,4);assert.equal(c.protocolProfileBehavior.followRedirects,false);
 for(const i of c.item){assert.equal(i.request.method,'GET');assert.equal(i.request.body,undefined);assert.equal(i.request.url,'{{api_origin}}'+manifest.reads[i.name].path+'{{p16_query}}');}
 for(const v of c.variable) assert.ok(['','false','{}'].includes(v.value));
 for(const word of ['pm.sendRequest','pm.environment.set','pm.collectionVariables.set','pm.execution.runRequest','pm.execution.setNextRequest'])assert.ok(!(pre+post).includes(word));
});
