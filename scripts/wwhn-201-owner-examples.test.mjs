import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {buildPostman} from './wwhn-201-outbound-contract-lock.mjs';

const spec=JSON.parse(fs.readFileSync(new URL('../docs/api-contract/WWHN-195-outbound-openapi.json',import.meta.url)));
const collection=buildPostman(spec);
const find=suffix=>collection.item.find(x=>x.name.endsWith(suffix));
test('human CHECK example preserves required owner body and strong version',()=>{
  const x=find('/{id}/checks');
  assert.deepEqual(JSON.parse(x.request.body.raw),{warehouse_id:'{{warehouse_id}}',confirmed:true});
  assert.equal(x.request.header.find(h=>h.key==='If-Match').value,'"{{document_version}}"');
});
test('CHECK reconcile uses the original operation key and warehouse',()=>{
  const x=find('/{id}/checks/requests/{key}');
  assert.ok(x.request.url.raw.includes('/requests/{{idempotency_key}}?warehouse_id={{warehouse_id}}'));
  assert.ok(!x.request.url.raw.includes('{key}'));
  assert.deepEqual(x.request.url.query,[{key:'warehouse_id',value:'{{warehouse_id}}'}]);
});
test('NFC history has a usable item identity without a write helper',()=>{
  const x=find('/{id}/items/{itemId}/nfc-history');
  assert.ok(x.request.url.raw.includes('/items/{{item_id}}/nfc-history'));
  assert.equal(x.request.method,'GET');
  assert.ok(collection.variable.some(v=>v.key==='item_id'&&v.value===''));
  assert.equal(collection.auth.bearer[0].value,'{{access_token}}');
});
test('CHECK assertions accept exact owner errors and canonical meta request ID',()=>{
  const script=find('/{id}/checks').event[0].script.exec.join('\n');
  for(const [status,code] of [[409,'DOCUMENT_CHECK_VERSION_CONFLICT'],[409,'DOCUMENT_CHECK_STATE_CONFLICT'],[422,'DOCUMENT_CHECK_PAYLOAD_INVALID'],[422,'BUSINESS_RULE_ERROR'],[428,'DOCUMENT_CHECK_IDENTITY_REQUIRED']]){
    const pm={response:{code:status,json:()=>({error:{code},meta:{request_id:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'}})},test:(_name,fn)=>fn(),expect:value=>({to:{include:expected=>assert.ok(value.includes(expected)),be:{get ok(){assert.ok(value);return true;}}}})};
    vm.runInNewContext(script,{pm});
  }
});
test('framework validation may trace through the actual HTTP Request-ID header',()=>{
  const script=find('/{id}/checks').event[0].script.exec.join('\n');
  const pm={response:{code:422,json:()=>({error:{code:'BUSINESS_RULE_ERROR'}}),headers:{get:key=>key==='X-Request-ID'?'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa':null}},test:(_name,fn)=>fn(),expect:value=>({to:{include:expected=>assert.ok(value.includes(expected)),be:{get ok(){assert.ok(value);return true;}}}})};
  vm.runInNewContext(script,{pm});
});
