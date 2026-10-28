import {test} from 'node:test';
import assert from 'node:assert/strict';
import {rewriteVendor} from './rewrite-vendor-compose.mjs';

test('existing service mount and process configuration is preserved exactly',()=>{
  const model={services:{backend:{image:'sha256:TEST',user:'33:33',volumes:[{source:'wms_vendor',target:'/var/www/html/vendor',read_only:true}],environment:{AUTO_MIGRATE:'false'}},queue:{entrypoint:['EXISTING'],image:'sha256:TEST'}},volumes:{wms_vendor:{external:true,name:'old'},unrelated:{name:'kept'}}};
  const after=rewriteVendor(model,'wms_vendor','old','hoa_nam_green_p06_vendor_123456abcdef');
  assert.deepEqual(after.services,model.services);
  assert.deepEqual(after.volumes.unrelated,model.volumes.unrelated);
  assert.deepEqual(after.volumes.wms_vendor,{external:true,name:'hoa_nam_green_p06_vendor_123456abcdef'});
  assert.equal(model.volumes.wms_vendor.name,'old');
});
test('wrong origin and unreviewed target are rejected',()=>{
  const model={services:{backend:{}},volumes:{wms_vendor:{name:'old'}}};
  assert.throws(()=>rewriteVendor(model,'wms_vendor','wrong','hoa_nam_green_p06_vendor_123456abcdef'));
  assert.throws(()=>rewriteVendor(model,'wms_vendor','old','production_vendor'));
});
