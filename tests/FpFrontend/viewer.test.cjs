const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');const path=require('node:path');
test('shared viewer navigates, transforms, resets, handles denial and restores focus',()=>{
 const nodes={};function node(){return {listeners:{},hidden:false,style:{},dataset:{},addEventListener(name,fn){this.listeners[name]=fn;},removeAttribute(name){delete this[name];},focus(){this.focused=true;}};}
 for(const id of ['viewer','image','status','prev','next','zoom-in','zoom-out','rotate-left','rotate-right','fit','reset','fullscreen','close'])nodes[id]=node();
 const buttons=['Foto','KTP','Ijazah'].map((label,i)=>Object.assign(node(),{dataset:{label,url:'/private/'+i}}));
 nodes.viewer.showModal=()=>nodes.viewer.open=true;nodes.viewer.close=()=>{nodes.viewer.open=false;nodes.viewer.listeners.close();};nodes.viewer.requestFullscreen=()=>nodes.viewer.fullscreen=true;
 const document={querySelector:s=>nodes[s.slice(1)],querySelectorAll:()=>buttons,fullscreenElement:null,exitFullscreen(){}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../resources/js/fp/viewer.js'),'utf8'),{document});
 buttons[0].listeners.click();assert.equal(nodes.image.src,'/private/0');nodes.image.onload();assert.equal(nodes.image.hidden,false);
 nodes['zoom-in'].onclick();assert.match(nodes.image.style.transform,/1.25/);nodes['rotate-right'].onclick();assert.match(nodes.image.style.transform,/90deg/);
 nodes.next.onclick();assert.equal(nodes.image.src,'/private/1');assert.match(nodes.image.style.transform,/scale\(1\) rotate\(0deg\)/);
 nodes.prev.onclick();assert.equal(nodes.image.src,'/private/0');nodes.image.onerror();assert.equal(nodes.image.hidden,true);
 nodes.fullscreen.onclick();assert.equal(nodes.viewer.fullscreen,true);nodes.close.onclick();assert.equal(nodes.viewer.open,false);assert.equal(buttons[0].focused,true);assert.equal(nodes.image.src,undefined);
});
test('onboarding sends CSRF and shows only receipt fields, preserves key on failure',async()=>{
 const input={value:'same-request-key'};const form={action:'/fp-onboarding',elements:{idempotency_key:input},querySelector:()=>button,addEventListener(n,fn){this.submit=fn;},reset(){this.resetCalled=true;}};
 for(const key of ['profile_photo','ktp','ijazah'])form.elements[key]={files:[{type:'image/jpeg',size:100}]};
 const button={disabled:false},message={};let sent;
 const document={querySelector:s=>s==='#fp-form'?form:s==='#message'?message:s==='meta[name="csrf-token"]'?{content:'csrf-test'}:null};
 const sandbox={document,crypto:{randomUUID:()=> 'fresh-key'},FormData:class{},createImageBitmap:async()=>({width:640,height:480,close(){}}),fetch:async(url,options)=>{sent=options;return {ok:true,json:async()=>({message:'Diterima',receipt:'opaque-receipt',nip:9999,nik:'secret'})};}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../resources/js/fp/onboarding.js'),'utf8'),sandbox);
 await form.submit({preventDefault(){}});assert.equal(sent.headers['X-CSRF-TOKEN'],'csrf-test');assert.match(message.textContent,/opaque-receipt/);assert.doesNotMatch(message.textContent,/9999|secret/);assert.equal(button.disabled,false);
 sandbox.fetch=async()=>({ok:false,json:async()=>({message:'Coba kembali'})});const key=input.value;await form.submit({preventDefault(){}});assert.equal(input.value,key);
});
