'use strict';

const assert = require('node:assert/strict');
const {test} = require('node:test');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const {spawn,execFileSync} = require('node:child_process');
const crypto = require('node:crypto');
const {pathToFileURL} = require('node:url');
const newman = require('newman');

const generators = require('postman-collection/lib/superstring/dynamic-variables');
const root = path.resolve(__dirname, '../..');
const scratch = path.join(root, 'storage/framework/testing/api-toolchain');
fs.mkdirSync(scratch, {recursive:true});

test('all installed Postman dynamic generators have usable values from the patched engine', () => {
  assert.ok(Object.keys(generators).length > 100);
  for (const [name, entry] of Object.entries(generators)) {
    assert.equal(typeof entry.generator, 'function', name);
    const value = entry.generator();
    assert.ok(value !== undefined && value !== null, name);
    assert.ok(String(value).length > 0, name);
  }
  assert.match(generators.$randomUUID.generator(), /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i);
  assert.equal(typeof generators.$randomBoolean.generator(), 'boolean');
  assert.match(generators.$randomCurrencyCode.generator(), /^[A-Z]{3}$/);
});

test('legacy Postman aliases resolve to modern official Faker and CSV, not bundled old engines', () => {
  const {fakerEN} = require('@faker-js/faker');
  assert.equal(require('../../tools/api-compat/faker/index.cjs').fake, fakerEN.helpers.fake);
  assert.equal(typeof require('csv-parse').parse, 'function');
  assert.deepEqual(require('../../tools/api-compat/csv/sync.cjs')('sku,qty\nTEST,1', {columns:true}), [{sku:'TEST',qty:'1'}]);
  const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'));
  const modern = Object.entries(lock.packages).filter(([name]) => /(?:@faker-js\/faker|csv-parse|node-forge)$/.test(name));
  assert.ok(modern.some(([name, entry]) => name.endsWith('@faker-js/faker') && entry.version === '10.6.0'));
  assert.ok(modern.some(([name, entry]) => name.endsWith('csv-parse') && entry.version === '7.0.3'));
  assert.ok(modern.filter(([name]) => name.endsWith('@faker-js/faker')).every(([,entry]) => entry.version === '10.6.0'));
  assert.ok(modern.filter(([name]) => name.endsWith('node-forge')).every(([,entry]) => entry.version !== '1.4.0'));
});

test('Faker constructor interpolation cannot execute arbitrary code', () => {
  const faker = require('../../tools/api-compat/faker/index.cjs');
  assert.throws(() => faker.fake('{{person.firstName.constructor("globalThis.__hnToolchainRce=1")()}}'));
  assert.equal(globalThis.__hnToolchainRce, undefined);
});

test('CSV duplicate prototype columns remain own fields without prototype replacement', async () => {
  const parse = require('../../tools/api-compat/csv/index.cjs');
  const rows = await new Promise((resolve,reject) => parse('__proto__,__proto__,qty\na,b,1',
    {columns:true,group_columns_by_name:true},(error,data) => error?reject(error):resolve(data)));
  assert.equal(Object.getPrototypeOf(rows[0]), Object.prototype);
  assert.equal(Object.hasOwn(rows[0],'__proto__'), true);
  assert.deepEqual(rows[0].__proto__, ['a','b']);
});

test('source preparation is idempotent and rejects unknown or modified vendor files', async()=>{
  const {transformVendor,prepareApiToolchain}=await import(pathToFileURL(path.join(root,'scripts/prepare-api-toolchain.mjs')).href);
  const original="const parse=require('csv-parse');\n";
  const expected=crypto.createHash('sha256').update(original).digest('hex');
  const from="require('csv-parse')",to="require('../../adapter.cjs')";
  const once=transformVendor(original,expected,from,to);
  assert.equal(transformVendor(once,expected,from,to),once);
  assert.throws(()=>transformVendor(original+'// changed',expected,from,to));
  assert.throws(()=>transformVendor(once+'// changed',expected,from,to));
  assert.throws(()=>transformVendor(original,undefined,from,to));
  assert.equal(prepareApiToolchain(root,true).changed_files,0);
  assert.equal(JSON.parse(execFileSync(process.execPath,[process.env.npm_execpath,'ls','--all','--json'],{cwd:root,encoding:'utf8',windowsHide:true})).problems,undefined);
});

test('Newman still executes CSV iterations, dynamic variables, scripts, JSON requests and assertion failures', async () => {
  const observed = [];
  const server = http.createServer(async (request,response) => {
    const chunks=[];for await (const chunk of request)chunks.push(chunk);
    const parsed = new URL(request.url,'http://127.0.0.1');
    const body = Buffer.concat(chunks).toString('utf8');
    observed.push({method:request.method,path:parsed.pathname,body});
    response.writeHead(200, {'Content-Type':'application/json'});
    response.end(JSON.stringify({ok:true,query:Object.fromEntries(parsed.searchParams),body:body?JSON.parse(body):null}));
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+server.address().port;
  const csv=path.join(scratch,'iterations.csv');
  fs.writeFileSync(csv,'value,code\n1,"001"\n2,"002"\n');
  const collection={info:{name:'LOCAL TOOLCHAIN TEST ONLY',schema:'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'},item:[{
    name:'CSV and dynamic read',request:{method:'GET',url:base+'/echo?value={{value}}&code={{code}}&guid={{$randomUUID}}&n={{$randomInt}}'},
    event:[{listen:'test',script:{type:'text/javascript',exec:[
      'pm.test("owner local HTTP",()=>pm.response.to.have.status(200));',
      'pm.test("CSV numeric casting",()=>pm.expect(pm.iterationData.get("value")).to.be.a("number"));',
      'pm.test("quoted CSV remains text",()=>pm.expect(pm.iterationData.get("code")).to.be.a("string"));',
      'pm.test("dynamic UUID",()=>pm.expect(pm.response.json().query.guid).to.match(/^[0-9a-f-]{36}$/i));',
    ]}}]}, {
      name:'JSON write to local echo only',request:{method:'POST',url:base+'/echo',header:[{key:'Content-Type',value:'application/json'}],body:{mode:'raw',raw:'{"sku":"TEST","value":{{value}}}'}},
      event:[{listen:'test',script:{type:'text/javascript',exec:['pm.test("JSON request",()=>pm.expect(pm.response.json().body.sku).to.equal("TEST"));']}}],
    }]};
  const run = (options) => new Promise((resolve,reject) => newman.run({...options,reporters:[]},(error,result)=>error?reject(error):resolve(result)));
  try {
    const result=await run({collection,iterationData:csv});
    assert.equal(result.run.failures.length,0,JSON.stringify(result.run.failures.map(f=>f.error?.message)));
    assert.equal(result.run.stats.requests.total,4);
    assert.equal(result.run.stats.assertions.total,10);
    assert.equal(observed.filter(r=>r.method==='POST').length,2);
    const negative=structuredClone(collection);
    negative.item=negative.item.slice(0,1);
    negative.item[0].request.url=base+'/echo';
    negative.item[0].event[0].script.exec=['pm.test("expected negative",()=>pm.expect(false).to.equal(true));'];
    const failure=await run({collection:negative});
    assert.equal(failure.run.failures.length,1);
  } finally {await new Promise(resolve=>server.close(resolve));}
});

test('OpenAPI converter retains generated paths and CLI tools fail closed on invalid input', async () => {
  const converter=require('openapi-to-postmanv2');
  const spec={openapi:'3.0.3',info:{title:'TEST',version:'1.0.0'},servers:[{url:'http://127.0.0.1'}],paths:{'/test':{get:{responses:{200:{description:'OK'}}}}}};
  const converted=await new Promise((resolve,reject)=>converter.convert({type:'json',data:spec},{folderStrategy:'Tags'},(error,result)=>error?reject(error):resolve(result)));
  assert.equal(converted.result,true);
  assert.equal(converted.output[0].type,'collection');
  assert.ok(JSON.stringify(converted.output).includes('/test'));
  const runCli=(file,args)=>new Promise((resolve,reject)=>{
    const child=spawn(process.execPath,[file,...args],{cwd:root,windowsHide:true});
    let stderr='';child.stderr.on('data',b=>{stderr+=b});child.stdout.resume();child.on('error',reject);
    child.on('exit',code=>resolve({code,stderr}));
  });
  const invalid=path.join(scratch,'invalid.json');fs.writeFileSync(invalid,'{');
  const cli=await runCli(require.resolve('newman/bin/newman'),['run',invalid]);
  assert.notEqual(cli.code,0);
});
