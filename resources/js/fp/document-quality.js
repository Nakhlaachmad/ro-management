export function nikResult(text, expected) {
 const lines=String(text||'').split(/\r?\n/),candidates=new Set();
 for(const line of lines){
  const matches=line.match(/(?<!\d)(?:\d[\s.-]?){15}\d(?!\d)/g)||[];
  for(const match of matches){const digits=match.replace(/\D/g,'');if(digits.length===16)candidates.add(digits);}
 }
 if(candidates.size!==1)return {state:'manual',reason:'NIK tidak terbaca dengan pasti. Periksa dokumen secara manual bersama HRD.'};
 return [...candidates][0]===String(expected)?{state:'ready',reason:'Kandidat NIK sesuai biodata. Keaslian tetap diperiksa HRD.'}:{state:'mismatch',reason:'Kandidat NIK berbeda dari biodata. Periksa NIK atau konfirmasi pemeriksaan manual; NIK tidak diubah otomatis.'};
}
export function ocrResult(type, data, expected) {
 if(!data || !Number.isFinite(data.confidence) || data.confidence<45)return {state:'manual',reason:'OCR ambigu. Dokumen akan diperiksa manual oleh HRD.'};
 if(type==='ktp')return nikResult(data.text,expected);
 const words=String(data.text||'').match(/[A-Za-zÀ-ÿ]{3,}/g)||[];
 return words.length>=5?{state:'ready',reason:'Teks dapat dibaca. Ini bukan verifikasi keaslian ijazah.'}:{state:'manual',reason:'Teks ijazah belum terbaca dengan pasti. Foto ulang atau lanjutkan pemeriksaan manual HRD.'};
}
export function qualityMetrics(pixels,width,height) {
 const gray=new Float32Array(width*height);let sum=0,clipped=0,lap=0,lap2=0,n=0;
 for(let i=0;i<gray.length;i++){const v=.299*pixels[i*4]+.587*pixels[i*4+1]+.114*pixels[i*4+2];gray[i]=v;sum+=v;if(v<12||v>250)clipped++;}
 for(let y=1;y<height-1;y++)for(let x=1;x<width-1;x++){const i=y*width+x,v=4*gray[i]-gray[i-1]-gray[i+1]-gray[i-width]-gray[i+width];lap+=v;lap2+=v*v;n++;}
 return {brightness:sum/gray.length,clipped:clipped/gray.length,sharpness:n?Math.max(0,lap2/n-(lap/n)**2):0};
}
export function qualityResult(type,width,height,metrics) {
 if(Math.min(width,height)<240||width*height>16000000)return {state:'invalid',reason:'Resolusi tidak sesuai batas backend: minimal 240 px per sisi, maksimal 16 megapiksel.'};
 const issues=[];
 if(metrics.brightness<45||(metrics.brightness>245&&metrics.sharpness<50)||(metrics.clipped>.85&&metrics.sharpness<50))issues.push('pencahayaan terlalu gelap/terang');
 if(metrics.sharpness<50)issues.push('gambar mungkin blur atau terlalu sedikit detail');
 if(type==='ktp'&&width<height)issues.push('KTP sebaiknya landscape; putar gambar jika diperlukan');
 if(issues.length)return {state:'retake',reason:issues.join('; ')+'. Foto ulang disarankan; HRD dapat memeriksa manual.'};
 return {state:'ready',reason:'Kualitas dasar memadai. Pastikan seluruh sisi dokumen terlihat.'};
}
export function stopStream(stream){stream?.getTracks?.().forEach(track=>track.stop());}
export class CameraSession {
 constructor(media){this.media=media;this.stream=null;this.generation=0;}
 async open(){const generation=++this.generation;this.closeTracks();const stream=await this.media.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}}});if(generation!==this.generation){stopStream(stream);return null;}this.stream=stream;return stream;}
 closeTracks(){stopStream(this.stream);this.stream=null;}
 close(){this.generation++;this.closeTracks();}
}