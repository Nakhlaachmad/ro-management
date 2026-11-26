export class LocalOcr {
 constructor(factory,base='/fp/ocr/v7.0.0'){this.factory=factory;this.base=base;this.worker=null;this.pending=null;this.queue=Promise.resolve();this.generation=0;}
 async getWorker(){
  if(this.worker)return this.worker;
  if(!this.pending){const generation=this.generation;this.pending=this.factory('eng',1,{workerPath:this.base+'/worker.min.js',corePath:this.base+'/core',langPath:this.base+'/lang',workerBlobURL:false,cacheMethod:'none',gzip:true,logger:()=>{},errorHandler:()=>{}}).then(async worker=>{if(generation!==this.generation){await worker.terminate();throw Error('Worker closed');}this.worker=worker;return worker;});}
  return this.pending;
 }
 recognize(image){
  const generation=this.generation;
  const task=this.queue.catch(()=>{}).then(async()=>{if(generation!==this.generation)throw Error('OCR cancelled');return (await this.getWorker()).recognize(image);});
  this.queue=task.then(()=>undefined,()=>undefined);return task;
 }
 async close(){this.generation++;const worker=this.worker;this.worker=null;this.pending=null;this.queue=Promise.resolve();if(worker)await worker.terminate();}
}