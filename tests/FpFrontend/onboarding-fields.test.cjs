const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../../resources/js/fp/onboarding.js'),'utf8');
function harness(initial={}) {
 const node=value=>({value,checked:false,readOnly:false,listeners:{},addEventListener(n,f){this.listeners[n]=f;},checkValidity(){return true;},setAttribute(){},dispatchEvent(event){this.listeners[event.type]?.(event);}});
 const phone=node(initial.phone||'81234567890'),bank=node('0012345678'),ktp=node('Synthetic KTP'),address=node(initial.address||'Synthetic domicile'),same=node('1');same.checked=initial.same||false;
 const elements={phone,bank_account:bank,address_ktp:ktp,address_current:address,address_same_as_ktp:same,idempotency_key:node('synthetic-key')};
 for(const name of ['profile_photo','ktp','ijazah'])elements[name]={files:[{type:'image/png',size:500}]};
 const button={},message={},form={elements,action:'/fp-onboarding',querySelector(){return button;},addEventListener(n,f){this[n]=f;},reset(){}},events={};let payload;
 class FormData {constructor(){this.values={phone:phone.value,bank_account:bank.value,address_current:address.value};}set(n,v){this.values[n]=v;}}
 vm.runInNewContext(source,{document:{querySelector:s=>s==='#fp-form'?form:s==='#message'?message:s==='meta[name="csrf-token"]'?{content:'synthetic-csrf'}:null},window:{addEventListener(n,f){events[n]=f;}},Event:class{constructor(type){this.type=type;}},FormData,createImageBitmap:async()=>({width:640,height:480,close(){}}),crypto:{randomUUID:()=> 'synthetic-key'},fetch:async(url,options)=>{payload=options.body.values;return {ok:true,json:async()=>({message:'OK',receipt:'opaque-receipt'})};}});
 return {phone,bank,ktp,address,same,form,events,payload:()=>payload};
}
test('BCA digits preserve zeroes and enforce maximum ten',()=>{const h=harness();h.bank.value='00123456789';h.bank.listeners.input();assert.equal(h.bank.value,'0012345678');h.bank.value='00A123-4567';h.bank.listeners.input();assert.equal(h.bank.value,'001234567');});
test('letters cannot be typed or pasted into numeric inputs',()=>{const h=harness();for(const field of [h.bank,h.phone]){let prevented=false;field.listeners.beforeinput({inputType:'insertText',data:'a',preventDefault(){prevented=true;}});assert.equal(prevented,true);const old=field.value;field.listeners.paste({clipboardData:{getData:()=> '123-ABC'},preventDefault(){}});assert.equal(field.value,old);}});
test('phone uses at most thirteen national digits',()=>{const h=harness();h.phone.value='81234567890123';h.phone.listeners.input();assert.equal(h.phone.value,'8123456789012');});
test('pasting country prefix retains only one prefix in submitted payload',async()=>{const h=harness();h.phone.selectionStart=0;h.phone.selectionEnd=h.phone.value.length;h.phone.listeners.paste({clipboardData:{getData:()=> '+6281234567890'},preventDefault(){}});assert.equal(h.phone.value,'81234567890');await h.form.submit({preventDefault(){}});assert.equal(h.payload().phone,'+6281234567890');assert.equal(h.payload().bank_account,'0012345678');});
test('unchecked existing domicile is never overwritten on opening form',()=>{const h=harness({address:'Existing synthetic domicile'});assert.equal(h.address.value,'Existing synthetic domicile');h.ktp.value='Updated KTP';h.ktp.listeners.input();assert.equal(h.address.value,'Existing synthetic domicile');assert.equal(h.address.readOnly,false);});
test('same-address checkbox follows KTP edits and restores manual entry on uncheck',()=>{const h=harness();h.same.checked=true;h.same.listeners.change();assert.equal(h.address.value,'Synthetic KTP');assert.equal(h.address.readOnly,true);h.ktp.value='Updated KTP';h.ktp.listeners.input();assert.equal(h.address.value,'Updated KTP');h.same.checked=false;h.same.listeners.change();assert.equal(h.address.value,'Synthetic domicile');assert.equal(h.address.readOnly,false);});
test('reopened checked form synchronizes addresses and country prefix safely',()=>{const h=harness({same:true,phone:'+6281234567890'});assert.equal(h.phone.value,'81234567890');assert.equal(h.address.value,'Synthetic KTP');h.ktp.value='Restored KTP';h.events.pageshow();assert.equal(h.address.value,'Restored KTP');});
test('sensitive data has no persistent browser storage',()=>{assert.doesNotMatch(source,/localStorage|sessionStorage|indexedDB/);});
