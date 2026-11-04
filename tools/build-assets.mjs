import {build} from 'esbuild';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const output=path.join(root,'assets/build'),check=process.argv.includes('--check'),files=new Map();
const common={bundle:true,minify:true,sourcemap:false,legalComments:'eof',target:['es2020'],logLevel:'warning',write:false};
for(const[entry,outfile,format]of[['venue-app.mjs','venue-app.js','iife'],['preview3d.mjs','preview3d.mjs','esm'],['geometry-worker.mjs','geometry-worker.js','iife']]){
  const result=await build({...common,entryPoints:[path.join(root,'assets/src',entry)],outfile:path.join(output,outfile),format});
  for(const file of result.outputFiles)files.set(path.basename(file.path),file.contents);
}
const licenses=await readFile(path.join(root,'node_modules/three/LICENSE'),'utf8');files.set('THIRD-PARTY-LICENSES.txt',Buffer.from(`Three.js 0.186.1\n${licenses}\n`));
const outputs=['venue-app.js','venue-app.css','preview3d.mjs','geometry-worker.js','THIRD-PARTY-LICENSES.txt'];
const manifest={format:1,files:{}};for(const file of outputs){const bytes=files.get(file);manifest.files[file]={bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')};}
const sourcePaths=['assets/src/venue-app.mjs','assets/src/venue-core.mjs','assets/src/venue-canvas.mjs','assets/src/venue-app.css','assets/src/preview3d.mjs','assets/src/geometry-worker.mjs','package.json','package-lock.json','tools/build-assets.mjs'];
const sourceHash=createHash('sha256');for(const file of sourcePaths)sourceHash.update(file).update(await readFile(path.join(root,file)));manifest.source_sha256=sourceHash.digest('hex');manifest.sources=sourcePaths;
files.set('manifest.json',Buffer.from(`${JSON.stringify(manifest,null,2)}\n`));
if(check){for(const[name,bytes]of files){const existing=await readFile(path.join(output,name)).catch(()=>null);if(!existing||!Buffer.from(bytes).equals(existing))throw new Error(`Built asset is stale: ${name}. Run npm run build.`);}console.log('PASS: bundled runtime assets match current sources.');}
else{await mkdir(output,{recursive:true});for(const[name,bytes]of files)await writeFile(path.join(output,name),bytes);console.log(`Built ${outputs.length} local runtime assets.`);}
