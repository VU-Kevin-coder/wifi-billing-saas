import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../../resources/js/form-guard.js',import.meta.url),'utf8');
function fixture() {
 const listeners={}, wl={}; let done, calls=0, navigated, saved=0, restored=0;
 class Form {constructor(){this.method='post';this.action='https://local.test/platform/configuration/control';this.dataset={};this.elements=[{type:'text',disabled:false},{type:'submit',disabled:false}];} hasAttribute(n){return n==='data-ui-async-settings';} dispatchEvent(e){if(e.type==='ui:form-saved')saved++;if(e.type==='ui:form-restored')restored++;} }
 const form=new Form(), msg={hidden:true,textContent:''};
 const document={addEventListener:(n,f)=>listeners[n]=f,querySelector:()=>msg,querySelectorAll:()=>form.dataset.uiSubmitting?[form]:[]};
 const window={FormDataEvent:class{},AbortController,fetch:()=>{calls++;return new Promise(r=>done=r)},location:{href:form.action,origin:'https://local.test',assign:x=>navigated=x},addEventListener:(n,f)=>wl[n]=f};
 vm.runInNewContext(source,{document,window,HTMLFormElement:Form,HTMLButtonElement:class{},HTMLInputElement:class{},FormData:class{},AbortController,URL,setTimeout,clearTimeout,CustomEvent:class{constructor(type){this.type=type}},queueMicrotask,crypto:{randomUUID:()=>''}});
 return {form,msg,window,wl,submit:()=>{const e={target:form,preventDefault(){this.defaultPrevented=true}};listeners.submit(e);return e},respond:r=>done(r),get calls(){return calls},get saved(){return saved},get restored(){return restored},get navigated(){return navigated}};
}
const tick=()=>new Promise(r=>setImmediate(r));
test('pending async save survives pageshow, blocks duplicate, then saves',async()=>{const f=fixture();f.submit();assert.equal(f.form.elements[0].disabled,true);f.wl.pageshow();f.submit();assert.equal(f.calls,1);f.respond({ok:true,status:200,json:async()=>({saved:true,redirect:f.form.action})});await tick();assert.equal(f.saved,1);assert.equal(f.navigated,f.form.action);assert.equal(f.form.elements[0].disabled,false)});
test('422 restores controls and permits correction and retry',async()=>{const f=fixture();f.submit();f.respond({ok:false,status:422,json:async()=>({errors:{reason:['Invalid reason']}})});await tick();assert.equal(f.msg.textContent,'Invalid reason');assert.equal(f.restored,1);assert.equal(f.form.elements[0].disabled,false);f.submit();assert.equal(f.calls,2);f.respond({ok:false,status:422,json:async()=>({})});await tick()});
test('ambiguous response locks retry and reports uncertainty',async()=>{const f=fixture();f.submit();f.respond({ok:false,status:500,json:async()=>({})});await tick();assert.equal(f.form.dataset.uiSaveUncertain,'true');assert.match(f.msg.textContent,/Reload and review/);f.submit();assert.equal(f.calls,1);assert.equal(f.saved,0)});
