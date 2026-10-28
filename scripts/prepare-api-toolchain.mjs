import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';

const marker='// HN_API_TOOLCHAIN_COMPAT_V1: patched engines, legacy method names only.\n';
const hashes={
  'postman-collection@4.4.0':'57e694355e6577e9aa1702f3b80e92f138cfd2a4718a899b3c3712fd8d10f83e',
  'postman-collection@5.3.1':'ca69ee0ba81a191d1fd0edcc2eeaa24e461c14f5cd8bfc6bd7fb7f6a423798a1',
  'newman@6.2.2':'a1535e6ef319e1bd224edab7ec18988a578c281c06b1a74ea7d8be57385114cc',
};
const digest=text=>crypto.createHash('sha256').update(text).digest('hex');

/** Never approve an unknown upstream file or execute a copied legacy engine. */
export function transformVendor(source,expected,original,replacement) {
  if(!expected)throw Error('Unreviewed API tool version');
  const prepared=source.startsWith(marker);
  const restored=prepared?source.slice(marker.length).replace(replacement,original):source;
  if(digest(restored)!==expected||restored.split(original).length!==2)throw Error('API tool source hash mismatch');
  const result=marker+restored.replace(original,replacement);
  if(prepared&&result!==source)throw Error('Previously prepared API tool changed');
  return result;
}

export function prepareApiToolchain(root,checkOnly=false) {
  root=fs.realpathSync(root);
  const modules=fs.realpathSync(path.join(root,'node_modules'));
  if(!modules.startsWith(root+path.sep))throw Error('Refuse to modify shared/external node_modules');
  const lock=JSON.parse(fs.readFileSync(path.join(root,'package-lock.json'),'utf8'));
  const targets=[];
  for(const [relative,entry]of Object.entries(lock.packages)) {
    let file,from,adapter,key;
    if(relative.endsWith('node_modules/postman-collection')) {
      file='lib/superstring/dynamic-variables.js';from="require('@faker-js/faker/locale/en')";
      adapter='tools/api-compat/faker/index.cjs';key='postman-collection@'+entry.version;
    }else if(relative==='node_modules/newman') {
      file='lib/run/options.js';from="require('csv-parse')";
      adapter='tools/api-compat/csv/index.cjs';key='newman@'+entry.version;
    }else continue;
    const filename=fs.realpathSync(path.join(root,relative,file));
    if(!filename.startsWith(modules+path.sep))throw Error('API tool path escaped node_modules');
    let modulePath=path.relative(path.dirname(filename),path.join(root,adapter)).split(path.sep).join('/');
    if(!modulePath.startsWith('.'))modulePath='./'+modulePath;
    const source=fs.readFileSync(filename,'utf8');
    const result=transformVendor(source,hashes[key],from,'require('+JSON.stringify(modulePath)+')');
    targets.push({filename,result,changed:result!==source,key});
  }
  if(targets.length!==3)throw Error('Unexpected Postman/Newman dependency inventory');
  if(checkOnly&&targets.some(t=>t.changed))throw Error('API toolchain not prepared; run npm run toolchain:prepare');
  // Validate the complete plan before any file is replaced. Only generated
  // dependency files change; package sources, lock and application stay intact.
  if(!checkOnly)for(const target of targets)if(target.changed) {
    const temporary=target.filename+'.hn-api-'+process.pid+'.tmp';
    fs.writeFileSync(temporary,target.result,{flag:'wx'});
    fs.renameSync(temporary,target.filename);
  }
  return {status:'API_TOOLCHAIN_READY',reviewed_files:targets.length,changed_files:targets.filter(t=>t.changed).length};
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
  try {console.log(JSON.stringify(prepareApiToolchain(process.cwd(),process.argv.includes('--check'))));}
  catch(error){console.error('API_TOOLCHAIN_REFUSED: '+error.message);process.exitCode=1;}
}
