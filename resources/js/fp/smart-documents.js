import {qualityMetrics,qualityResult,ocrResult,CameraSession} from './document-quality.js';
import {LocalOcr} from './ocr-client.js';
export async function encodeForUpload(canvas,maxBytes){
 for(let scale=0;scale<5;scale++){
  for(const quality of [.9,.8,.7,.6]){
   const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));
   if(blob&&blob.size<=maxBytes)return blob;
  }
  const width=Math.round(canvas.width*.8),height=Math.round(canvas.height*.8);
  if(Math.min(width,height)<240)break;
  const copy=document.createElement('canvas');copy.width=width;copy.height=height;copy.getContext('2d').drawImage(canvas,0,0,width,height);
  canvas.width=width;canvas.height=height;canvas.getContext('2d').drawImage(copy,0,0);copy.width=copy.height=0;
 }
 throw Error('Gambar masih terlalu besar. Gunakan upload file yang lebih kecil.');
}
export function captureBounds(width,height,type){
 const ratio=type==='ktp'?1.586:.707;let w=width*.85,h=w/ratio;
 if(h>height*.85){h=height*.85;w=h*ratio;}
 return {x:(width-w)/2,y:(height-h)/2,width:w,height:h};
}
export function initSmartDocuments(){
 const form=document.querySelector('#fp-form');if(!form)return;
 const optionalDocuments=form.dataset.optionalDocuments==='true';
 const names=optionalDocuments?['ktp','ijazah']:['profile_photo','ktp','ijazah'];
 const states=new Map(),tokens=new Map(),maxBytes=Number(form.dataset.maxFileBytes);
 const camera=new CameraSession(navigator.mediaDevices||{getUserMedia:async()=>{throw Error('Unavailable');}});
 const ocr=new LocalOcr(async(...args)=>(await import('tesseract.js')).createWorker(...args));
 const dialog=document.querySelector('#document-camera'),video=dialog.querySelector('video'),guide=dialog.querySelector('[data-camera-guide]'),cameraMessage=dialog.querySelector('[data-camera-message]');
 let cameraType=null,previousFocus=null,closed=false;
 const state=(name,result)=>{
  states.set(name,result);
  const label=document.querySelector('[data-document-state="'+name+'"]');
  const labels={checking:'Memeriksa',ready:'Layak',retake:'Perlu Foto Ulang',manual:'Perlu Pemeriksaan Manual',mismatch:'Perlu Pemeriksaan Manual',invalid:'Perlu Foto Ulang'};
  label.textContent=labels[result.state]||'Belum dipilih';label.dataset.state=result.state;
  document.querySelector('[data-quality-feedback="'+name+'"]').textContent=result.reason;
  const manual=document.querySelector('[data-manual-option="'+name+'"]');manual.hidden=!['manual','retake','mismatch'].includes(result.state);
 };
 const metrics=async(image)=>{
  const canvas=document.createElement('canvas'),scale=Math.min(1,384/Math.max(image.width,image.height));
  canvas.width=Math.round(image.width*scale);canvas.height=Math.round(image.height*scale);
  canvas.getContext('2d',{willReadFrequently:true}).drawImage(image,0,0,canvas.width,canvas.height);
  const data=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height);
  canvas.width=canvas.height=0;
  if(typeof Worker==='undefined')return qualityMetrics(data.data,data.width,data.height);
  return new Promise(resolve=>{
   const worker=new Worker(new URL('./quality-worker.js',import.meta.url),{type:'module'});
   const finish=value=>{clearTimeout(timer);worker.terminate();resolve(value);};
   const fallback=()=>finish(qualityMetrics(data.data,data.width,data.height));
   const timer=setTimeout(fallback,5000);worker.onmessage=event=>finish(event.data);worker.onerror=fallback;
   // Keep a copy only until analysis finishes so worker failure has a bounded fallback.
   const copy=data.data.slice();worker.postMessage({buffer:copy.buffer,width:data.width,height:data.height},[copy.buffer]);
  });
 };
 const inspect=async(name,file,token)=>{
  let image,canvas;
  try{
   if(!file||file.size>maxBytes||!['image/jpeg','image/png'].includes(file.type))return {state:'invalid',reason:'Pilih JPEG/PNG sesuai batas ukuran layanan.'};
   image=await createImageBitmap(file);
   if(Math.min(image.width,image.height)<240||image.width*image.height>16000000)return qualityResult(name,image.width,image.height,{});
   const quality=qualityResult(name,image.width,image.height,await metrics(image));
   if(name==='profile_photo'||quality.state!=='ready')return quality;
   canvas=document.createElement('canvas');const scale=Math.min(1,1800/Math.max(image.width,image.height));
   canvas.width=Math.round(image.width*scale);canvas.height=Math.round(image.height*scale);canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);
   if(tokens.get(name)!==token||closed)return {state:'manual',reason:'Pemeriksaan dibatalkan.'};
   let timer;
   const timeout=new Promise((_,reject)=>{timer=setTimeout(()=>{void ocr.close();reject(Error('Timeout'));},25000);});
   try{
    const result=await Promise.race([ocr.recognize(canvas),timeout]);
    return ocrResult(name,result.data,form.elements.nik.value);
   }catch{return {state:'manual',reason:'OCR tidak tersedia atau belum pasti. Konfirmasi pemeriksaan manual HRD atau foto ulang.'};}
   finally{clearTimeout(timer);}
  }catch{return {state:'manual',reason:'Pemeriksaan otomatis tidak tersedia. Gunakan preview dan pemeriksaan manual HRD.'};}
  finally{image?.close();if(canvas)canvas.width=canvas.height=0;}
 };
 const changed=name=>{
  const token=(tokens.get(name)||0)+1;tokens.set(name,token);
  document.querySelector('[data-manual="'+name+'"]').checked=false;document.querySelector('[data-framing="'+name+'"]').checked=false;
  const file=form.elements[name].files[0];state(name,{state:'checking',reason:'Memeriksa kualitas dan teks secara lokal…'});
  const promise=inspect(name,file,token).then(result=>{if(tokens.get(name)===token&&!closed)state(name,result);return result;});
  states.set(name,{state:'checking',promise});
 };
 const closeCamera=()=>{
  camera.close();video.pause();video.srcObject=null;if(dialog.open)dialog.close();cameraType=null;previousFocus?.focus();
 };
 for(const name of names){
  form.elements[name].addEventListener('change',()=>changed(name));
  document.querySelector('[data-rotate="'+name+'"]').addEventListener('click',async event=>{
   const file=form.elements[name].files[0];if(!file)return;
   event.currentTarget.disabled=true;let image,canvas;
   try{
    if(file.size>maxBytes||!['image/jpeg','image/png'].includes(file.type))throw Error('Pilih gambar yang didukung terlebih dahulu.');
    image=await createImageBitmap(file);if(Math.min(image.width,image.height)<240||image.width*image.height>16000000)throw Error('Resolusi tidak sesuai.');
    const scale=Math.min(1,2200/Math.max(image.width,image.height));if(Math.min(image.width,image.height)*scale<240)throw Error('Gunakan rotasi di perangkat agar resolusi tetap memadai.');canvas=document.createElement('canvas');canvas.width=Math.round(image.height*scale);canvas.height=Math.round(image.width*scale);
    const context=canvas.getContext('2d');context.translate(canvas.width,0);context.rotate(Math.PI/2);context.drawImage(image,0,0,Math.round(image.width*scale),Math.round(image.height*scale));
    const blob=await encodeForUpload(canvas,maxBytes),transfer=new DataTransfer;transfer.items.add(new File([blob],'document.jpg',{type:'image/jpeg'}));form.elements[name].files=transfer.files;form.elements[name].dispatchEvent(new Event('change'));
   }catch{state(name,{state:'manual',reason:'Putar otomatis tidak tersedia. Putar file di perangkat atau gunakan pemeriksaan manual.'});}
   finally{image?.close();if(canvas)canvas.width=canvas.height=0;event.currentTarget.disabled=false;}
  });
 }
 form.elements.nik.addEventListener('change',()=>{if(form.elements.ktp.files[0])changed('ktp');});
 for(const button of document.querySelectorAll('[data-camera]'))button.addEventListener('click',async()=>{
  closeCamera();previousFocus=button;cameraType=button.dataset.camera;const type=cameraType;cameraMessage.textContent='Menghubungkan kamera…';dialog.showModal();
  try{
   const stream=await camera.open();if(!stream||cameraType!==type)return;
   video.srcObject=stream;await video.play();
   const bounds=captureBounds(video.videoWidth,video.videoHeight,type);
   guide.style.left=(bounds.x/video.videoWidth*100)+'%';guide.style.top=(bounds.y/video.videoHeight*100)+'%';guide.style.width=(bounds.width/video.videoWidth*100)+'%';guide.style.height=(bounds.height/video.videoHeight*100)+'%';
   guide.dataset.type=type;cameraMessage.textContent='Posisikan seluruh dokumen di dalam bingkai, terang dan tidak blur.';
  }catch{camera.close();video.srcObject=null;cameraMessage.textContent='Kamera ditolak atau tidak tersedia. Tutup kamera dan gunakan upload dari perangkat.';}
 });
 dialog.querySelector('[data-camera-close]').addEventListener('click',closeCamera);
 dialog.addEventListener('cancel',closeCamera);dialog.addEventListener('close',()=>{camera.close();video.srcObject=null;});
 dialog.querySelector('[data-camera-capture]').addEventListener('click',async event=>{
  const type=cameraType;if(!type||!video.videoWidth){cameraMessage.textContent='Kamera belum siap. Gunakan upload bila diperlukan.';return;}
  event.currentTarget.disabled=true;const canvas=document.createElement('canvas');
  try{
   const b=captureBounds(video.videoWidth,video.videoHeight,type);canvas.width=Math.round(b.width);canvas.height=Math.round(b.height);canvas.getContext('2d').drawImage(video,b.x,b.y,b.width,b.height,0,0,canvas.width,canvas.height);
   closeCamera();const blob=await encodeForUpload(canvas,maxBytes),transfer=new DataTransfer;transfer.items.add(new File([blob],'capture.jpg',{type:'image/jpeg'}));
   form.elements[type].files=transfer.files;form.elements[type].dispatchEvent(new Event('change'));
  }catch{closeCamera();state(type,{state:'manual',reason:'Capture tidak berhasil. Upload gambar dari perangkat atau ambil foto ulang.'});}
  finally{canvas.width=canvas.height=0;event.currentTarget.disabled=false;}
 });
 window.fpSmartDocuments={
  async validate(){
   let valid=true;
   for(const name of names){
    if(optionalDocuments&&!form.elements[name].files[0])continue;
    const token=tokens.get(name);let result=states.get(name);if(result?.promise){await result.promise;if(tokens.get(name)!==token)return false;result=states.get(name);}
    if(!result){state(name,{state:'manual',reason:'Konfirmasi pemeriksaan manual HRD.'});result=states.get(name);}
    if(result.state==='invalid'||(['manual','retake','mismatch'].includes(result.state)&&!document.querySelector('[data-manual="'+name+'"]').checked))valid=false;
   }
   return valid;
  },
  close(){closed=true;closeCamera();void ocr.close();states.clear();tokens.clear();}
 };
 window.addEventListener('pagehide',()=>window.fpSmartDocuments.close());
 document.addEventListener('visibilitychange',()=>{if(document.hidden)closeCamera();});
 form.addEventListener('reset',()=>{window.fpSmartDocuments.close();});
 for(const button of document.querySelectorAll('[data-next],[data-back]'))button.addEventListener('click',closeCamera);
}
if(typeof document!=='undefined')initSmartDocuments();
