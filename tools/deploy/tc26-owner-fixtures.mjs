import fs from 'node:fs';
import {randomUUID} from 'node:crypto';

const stateFile = process.argv[2];
if (!stateFile?.startsWith('/opt/hoa-nam-green/backups/tc26-')) throw Error('Private state path required');
const state = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
const persist = () => fs.writeFileSync(stateFile, JSON.stringify(state), {mode:0o600});
const base = 'https://khohoanamdev.lptech.info.vn/api/v1';
const evidence = state.evidence ??= [];
const redact = value => Array.isArray(value) ? value.map(redact) : value && typeof value === 'object'
  ? Object.fromEntries(Object.entries(value).map(([k,v]) => [k,/token|password|cookie|authorization|phone|customer|address/i.test(k) && v !== null ? '[REDACTED]' : redact(v)])) : value;
let token;
async function call(label, method, path, body, extra={}) {
  const write = !['GET','HEAD'].includes(method) && !path.includes('resolve-code') && !path.includes('/auth/');
  const header = {Accept:'application/json', Origin:'https://khohoanamfe.lptech.info.vn', 'X-Request-ID':randomUUID(), ...extra};
  if(token) header.Authorization = 'Bearer '+token;
  if(body!==undefined) header['Content-Type']='application/json';
  const op = write ? (state.operations[label] ??= {method,path,body,extra,requestId:header['X-Request-ID'],phase:'UNSENT'}) : null;
  if(op && (op.phase!=='UNSENT')) {
    if(op.phase==='SUCCEEDED') return op.data;
    throw Error(label+': UNKNOWN; CHECK_ONLY, no replay/takeover');
  }
  if(op){op.phase='UNKNOWN';persist();}
  const response = await fetch(base+path,{method,headers:header,redirect:'error',signal:AbortSignal.timeout(45000),body:body===undefined?undefined:JSON.stringify(body)});
  const raw = await response.text(); let json;try{json=JSON.parse(raw)}catch{throw Error(label+': NON_JSON');}
  const e={label,method,path,status:response.status,request_id:response.headers.get('x-request-id'),at_utc:new Date().toISOString(),
    headers:Object.fromEntries([...response.headers].filter(([k])=>!/(authorization|cookie)/i.test(k))),body:redact(json)};
  evidence.push(e);persist();
  if(response.status===202 || ['PROCESSING','UNKNOWN'].includes(json.data?.receipt?.state)
    || json.data?.outcome==='UNKNOWN') throw Error(label+': UNKNOWN/CHECK_ONLY, no write retry');
  if(!response.ok || json.success!==true) throw Error(label+': HTTP '+response.status+' '+(json.error?.code??json.error_code));
  if(op){op.phase='SUCCEEDED';op.data=json.data;persist();}
  return json.data;
}
function key(label){return state.keys[label]??=randomUUID();}
const health = await call('health','GET','/health');
if(health.build.sha!==process.env.EXPECTED_SOURCE_SHA)throw Error('Runtime source mismatch');
if(state.smoke_completed) {console.log(JSON.stringify({status:'EXISTING_TC26_COMPLETE_NO_RELOGIN',fixture:state.fixture}));process.exit(0);}
state.operations??={};state.keys??={};persist();
if(Object.keys(state.operations).length) throw Error('Existing owner journal: inspect receipts/read-only; no automatic new login or write sequence');
const login = await call('login','POST','/auth/login',{email:state.email,password:state.password});
token=login.access_token;
const context=await call('select','POST','/auth/switch-context',{context_type:'WAREHOUSE',context_id:state.warehouse_id});
if(context.access_token)token=context.access_token;
const me = await call('me','GET','/auth/me');
if(me.active_warehouse_id!==state.warehouse_id)throw Error('Scope mismatch');
state.auth_session_id=me.auth_session_id;persist();
const current=await call('shift-current','GET','/mini-app/work-shifts/current?warehouse_id='+state.warehouse_id);
if(current!==null)throw Error('Existing shift is not reusable automatically');
const shift=await call('shift-start','POST','/mini-app/work-shifts/start',{warehouse_id:state.warehouse_id},{'Idempotency-Key':key('start')});
state.shift_id=shift.shift_id;persist();
const owner={'X-Work-Shift-ID':shift.shift_id};
const product=state.skus.PRODUCT, component=state.skus.COMPONENT;
const code=suffix=>'HN1|SKU='+product.code+'|ITEM=TC26-'+suffix;
async function inbound(label,items,post){
  const draft=key(label+'-draft'),scan=key(label+'-scan');
  const b={warehouse_id:state.warehouse_id,scan_session_id:scan,inbound_type:'receipt',supplier_id:state.supplier_id,name:'TC26 TEST '+label,items};
  const saved=await call(label+'-save','PUT','/mini-app/inbound/drafts/'+draft,b,{...owner,'If-Match':'"0"','Idempotency-Key':key(label+'-save')});
  const record=await call(label+'-record','POST','/mini-app/inbound/drafts/'+draft+'/record',
    {warehouse_id:state.warehouse_id,scan_session_id:scan},{...owner,'If-Match':'"'+saved.document.version+'"','Idempotency-Key':key(label+'-record')});
  const check=await call(label+'-human-check','POST','/inbound-documents/'+record.document_id+'/checks',
    {warehouse_id:state.warehouse_id,confirmed:true},{'If-Match':'"'+record.version+'"','Idempotency-Key':key(label+'-check')});
  if(post)await call(label+'-post','POST','/mini-app/inbound-documents/'+record.document_id+'/post-receipt',{},
    {...owner,'If-Match':'"'+record.version+'"','Idempotency-Key':key(label+'-post')});
  return {document_id:record.document_id,draft_id:draft,scan_session_id:scan,check_event_id:check.event_id};
}
// Dedicated TEST stock is received and issued ONLY through canonical owner commands.
const receipt=await inbound('stock',[
  {raw_code:code('OUTBOUND'),quantity:1,scan_source:'MANUAL'},
  {raw_code:code('WARRANTY'),quantity:1,scan_source:'MANUAL'},
  {raw_code:code('MILESTONE'),quantity:1,scan_source:'MANUAL'},
  {raw_code:'HN1|SKU='+component.code+'|ITEM=TC26-COMPONENT',quantity:1,scan_source:'MANUAL'},
],true);
const item=await call('tc11-resolve','POST','/mini-app/outbound/resolve-code',{warehouse_id:state.warehouse_id,raw_code:code('OUTBOUND')});
if(!item.eligible_for_outbound||item.available_qty<1||item.stock_effect!=='NONE')throw Error('TC11 fixture not eligible');
const outbound=await call('tc11-source-create','POST','/mini-app/outbound-documents',{
  warehouse_id:state.warehouse_id,name:'TC26 TEST TC11 source',recipient_name:'TC26 TEST Recipient',expected_total_qty:1,
  lines:[{sku_id:product.id,required_qty:1}]},{...owner,'Idempotency-Key':key('tc11-source-create')});
const draft=key('tc11-draft'),scan=key('tc11-scan');
const save=await call('tc11-save','PUT','/mini-app/drafts/'+draft,{warehouse_id:state.warehouse_id,operation:'OUTBOUND',scan_session_id:scan,
  codes:[],form:{p05_source:{document_id:outbound.document_id,version:outbound.version}}},
  {...owner,'If-Match':'"0"','Idempotency-Key':key('tc11-save')});
const timelineSource=await call('tc04-source-create','POST','/mini-app/outbound-documents',{
  warehouse_id:state.warehouse_id,name:'TC26 TEST TC04 source',recipient_name:'TC26 TEST Recipient',expected_total_qty:1,
  lines:[{sku_id:product.id,required_qty:1}]},{...owner,'Idempotency-Key':key('tc04-source-create')});
await call('tc04-explicit-check','POST','/outbound-documents/'+timelineSource.document_id+'/checks',{
  warehouse_id:state.warehouse_id,confirmed:true},{'If-Match':'"'+timelineSource.version+'"','Idempotency-Key':key('tc04-check')});
const timelineDraft=key('tc04-draft'),timelineScan=key('tc04-scan'),timelineItems=[{raw_code:code('MILESTONE'),scan_source:'MANUAL'}];
const timelineSaved=await call('tc04-save','PUT','/mini-app/drafts/'+timelineDraft,{
  warehouse_id:state.warehouse_id,operation:'OUTBOUND',scan_session_id:timelineScan,codes:timelineItems,
  form:{p05_source:{document_id:timelineSource.document_id,version:timelineSource.version}}},
  {...owner,'If-Match':'"0"','Idempotency-Key':key('tc04-save')});
await call('tc04-linked-record','POST','/mini-app/outbound/record',{
  warehouse_id:state.warehouse_id,source_document_id:timelineSource.document_id,source_version:timelineSource.version,
  expected_total_qty:1,items:timelineItems},{...owner,'Idempotency-Key':key('tc04-linked-record'),
    'X-Draft-ID':timelineDraft,'X-Draft-Scan-Session-ID':timelineScan,'X-Draft-Version':String(timelineSaved.document.version)});
const warrantyIssue=await call('tc12-product-issue-record','POST','/mini-app/outbound/record',{
  warehouse_id:state.warehouse_id,name:'TC26 TEST warranty source',recipient_name:'TC26 TEST Recipient',expected_total_qty:1,
  items:[{raw_code:code('WARRANTY'),scan_source:'MANUAL'}]}, {...owner,'Idempotency-Key':key('tc12-issue-record')});
await call('tc12-product-post','POST','/mini-app/outbound-documents/'+warrantyIssue.document_id+'/post-issue',{},
  {...owner,'If-Match':'"'+warrantyIssue.version+'"','Idempotency-Key':key('tc12-product-post')});
const warrantyItem=await call('warranty-identity','POST','/mini-app/warranty/resolve-code',{warehouse_id:state.warehouse_id,raw_code:code('WARRANTY')});
const warranty=await call('tc12-create','POST','/mini-app/warranty-cases',{
  warehouse_id:state.warehouse_id,warranty_case_code:'TC26-TEST-WARRANTY',item_id:warrantyItem.item_id,
  customer_name:'TC26 TEST Customer',customer_phone:'0900000000',description:'TC26 TEST intake',received_condition:'TEST'},
  {...owner,'Idempotency-Key':key('tc12-create')});
const checked=await call('tc12-checking','PATCH','/mini-app/warranty-cases/'+warranty.warranty_case_id,
  {warehouse_id:state.warehouse_id,status:'CHECKING'},{...owner,'If-Match':'"'+warranty.receipt.version+'"','Idempotency-Key':key('tc12-checking')});
const componentRead=await call('tc12-component-resolve','POST','/mini-app/warranty-component-issues/resolve-code',{
  warehouse_id:state.warehouse_id,warranty_case_id:warranty.warranty_case_id,raw_code:'TC26-COMPONENT'},
  {'X-Component-Command-Contract':'p19-component-command-v1'});
if(!componentRead.resolved||!componentRead.eligible_for_issue||!componentRead.case_allows_issue)throw Error('Component not eligible');
// TC07 stays NEW_ITEM_CANDIDATE; saving a TEST draft does not receive/post it.
const tc07Draft=key('tc07-draft'),tc07Scan=key('tc07-scan');
await call('tc07-save','PUT','/mini-app/inbound/drafts/'+tc07Draft,{warehouse_id:state.warehouse_id,scan_session_id:tc07Scan,
  inbound_type:'receipt',supplier_id:state.supplier_id,name:'TC26 TEST TC07 receiptable',items:[]},
  {...owner,'If-Match':'"0"','Idempotency-Key':key('tc07-save')});
await call('tc07-validate','POST','/mini-app/inbound/drafts/'+tc07Draft+'/validate-code',{
  warehouse_id:state.warehouse_id,scan_session_id:tc07Scan,client_event_id:key('tc07-event'),
  item:{raw_code:code('RECEIPTABLE'),quantity:1,scan_source:'MANUAL'}},{...owner,'If-Match':'"1"'});
const document=await call('tc04-detail','GET','/mini-app/outbound-documents/'+timelineSource.document_id+'?warehouse_id='+state.warehouse_id);
for(const k of ['created','checked','sent'])if(document.p12.event_evidence.milestones[k].availability!=='RECORDED')throw Error('Milestone '+k+' missing');
const caseDetail=await call('tc12-detail','GET','/mini-app/warranty-cases/'+warranty.warranty_case_id+'?warehouse_id='+state.warehouse_id);
if(caseDetail.product_source.availability!=='RECORDED')throw Error('Intake snapshot missing');
let notification;
for(let attempt=0;attempt<12&&!notification;attempt++){
  const notifications=await call('tc02-list-'+attempt,'GET','/notifications?cursor=start&per_page=100');
  notification=notifications.find(n=>n.subject_id===receipt.document_id&&n.source_snapshot?.actor_job_title_snapshot==='Nhân viên kho TEST');
  if(!notification)await new Promise(r=>setTimeout(r,2500));
}
if(!notification)throw Error('Queued TEST notification snapshot not available yet; read-only recheck required');
await call('tc02-detail','GET','/notifications/'+notification.id);
state.fixture={tc02:{recipient_id:notification.id},tc04:{document_id:timelineSource.document_id,source:'OUTBOUND',receipt_source:receipt.document_id},
  tc07:{draft_id:tc07Draft,scan_session_id:tc07Scan,raw_code:code('RECEIPTABLE'),supplier_id:state.supplier_id,quantity_limit:1},
  tc11:{source_document_id:outbound.document_id,source_version:outbound.version,draft_id:draft,scan_session_id:scan,
    draft_version:save.document.version,raw_code:code('OUTBOUND'),item_id:item.item_id},
  tc12:{case_id:warranty.warranty_case_id,status:checked.receipt.status,component_item_id:componentRead.item_id}};
state.smoke_completed=true;persist();
console.log(JSON.stringify({status:'TC26_OWNER_FIXTURES_PASS',source_sha:health.build.sha,fixture:state.fixture,
  original_family_drafts_are_not_transferable:true,evidence:redact(evidence.filter(e=>!e.path.startsWith('/auth/')))}));
