import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import fs from 'node:fs';
import path from 'node:path';
function localOcrAssets() {
 return {name:'victory-local-ocr-assets',closeBundle(){
  const root='public/fp/ocr/v7.0.0';fs.mkdirSync(path.join(root,'core'),{recursive:true});fs.mkdirSync(path.join(root,'lang'),{recursive:true});
  fs.copyFileSync('node_modules/tesseract.js/dist/worker.min.js',path.join(root,'worker.min.js'));
  for(const name of fs.readdirSync('node_modules/tesseract.js-core').filter(name=>name.endsWith('.wasm.js')))fs.copyFileSync(path.join('node_modules/tesseract.js-core',name),path.join(root,'core',name));
  fs.copyFileSync('node_modules/@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz',path.join(root,'lang','eng.traineddata.gz'));
  fs.copyFileSync('node_modules/tesseract.js/LICENSE.md',path.join(root,'LICENSE-tesseract.txt'));
  fs.copyFileSync('node_modules/tesseract.js-core/LICENSE',path.join(root,'LICENSE-core.txt'));
  fs.writeFileSync(path.join(root,'MODEL-NOTICE.txt'),'@tesseract.js-data/eng 1.0.0, MIT metadata; https://github.com/naptha/tessdata; 4.0.0_best_int model.');
 }};
}
export default defineConfig({
 plugins:[laravel({input:['resources/css/app.css','resources/js/app.js','resources/js/fp/smart-documents.js'],refresh:true}),localOcrAssets()],
});
