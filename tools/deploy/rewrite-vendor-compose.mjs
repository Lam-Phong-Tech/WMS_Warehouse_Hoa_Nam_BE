import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import yaml from 'js-yaml';

export function rewriteVendor(model,key,oldName,newName) {
  if(!model||typeof model!=='object'||!model.services||!model.volumes?.[key]
    ||model.volumes[key].name!==oldName||oldName===newName
    ||!/^hoa_nam_green_p06_vendor_[0-9a-f]{12}$/.test(newName))throw Error('Unexpected vendor override identity');
  const result=structuredClone(model);
  result.volumes[key]={external:true,name:newName};
  return result;
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
  const [folder,key,oldName,newName]=process.argv.slice(2);
  const root=fs.realpathSync(folder);
  if(!root.startsWith('/opt/hoa-nam-green/releases/')||root===fs.realpathSync('/opt/hoa-nam-green/current'))throw Error('Refuse active/outside release');
  const file=path.join(root,'docker-compose.vendor-release.yml');
  const model=yaml.load(fs.readFileSync(file,'utf8'));
  const next=rewriteVendor(model,key,oldName,newName);
  fs.writeFileSync(file,JSON.stringify(next,null,2),{mode:0o640});
  console.log(JSON.stringify({status:'VENDOR_MAPPING_ONLY',services_preserved:Object.keys(next.services).length}));
}
