const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../resources/js/fp/onboarding.js'),'utf8');
function harness(files={}){
 let calls=0;
 const button={},message={},form={dataset:{optionalDocuments:'true'},action:'/fp-onboarding',elements:{idempotency_key:{value:'synthetic-key'},ktp:{files:[]},ijazah:{files:[]}},querySelector:()=>button,addEventListener(name,fn){this[name]=fn;},reset(){}};
 for(const [name,file] of Object.entries(files))form.elements[name].files=[file];
 vm.runInNewContext(source,{document:{querySelector:s=>s==='#fp-form'?form:s==='#message'?message:s==='meta[name="csrf-token"]'?{content:'synthetic-csrf'}:null},crypto:{randomUUID:()=> 'synthetic-key'},FormData:class{},createImageBitmap:async()=>({width:640,height:480,close(){}}),fetch:async()=>{calls++;return {ok:true,json:async()=>({message:'Diterima',receipt:'opaque-receipt'})};}});
 return {form,message,calls:()=>calls};
}
test('no BCA or documents required by optional frontend; no photo element accessed',async()=>{const h=harness();await h.form.submit({preventDefault(){}});assert.equal(h.calls(),1);assert.match(h.message.textContent,/Diterima/);});
test('one optional KTP can be submitted without ijazah',async()=>{const h=harness({ktp:{type:'image/png',size:500}});await h.form.submit({preventDefault(){}});assert.equal(h.calls(),1);});
test('an optional uploaded invalid file is still blocked',async()=>{const h=harness({ijazah:{type:'application/pdf',size:500}});await h.form.submit({preventDefault(){}});assert.equal(h.calls(),0);});
