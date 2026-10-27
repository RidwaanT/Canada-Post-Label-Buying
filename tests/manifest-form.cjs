/** Selection remains explicit across external form controls and recovery forms. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const script=fs.readFileSync(__dirname+'/../assets/js/manifests.js','utf8');
const handlers={},picks=[{checked:false},{checked:false}],ready={checked:false},button={},counter={};
const form={elements:{ready},addEventListener:(event,fn)=>{handlers[event]=fn;}};
ready.form=form;picks.forEach(p=>p.form=form);
const box={querySelectorAll:()=>picks,querySelector:selector=>selector==='[data-finish-submit]'?button:selector==='[data-pick-count]'?counter:{addEventListener:(event,fn)=>{handlers[selector]=fn;}},addEventListener:(event,fn)=>{handlers[event]=fn;}};
vm.runInNewContext(script,{document:{querySelector:()=>box,getElementById:()=>form}});
assert.equal(button.disabled,true);
handlers['[data-pick-all]']();assert.equal(picks[0].checked,true);assert.equal(button.disabled,true);
ready.checked=true;handlers.change({target:ready});assert.equal(button.disabled,false);
handlers.change({target:{form,name:'handoff_id'}});assert.equal(ready.checked,false);assert.equal(button.disabled,true);
ready.checked=true;handlers.change({target:ready});handlers.change({target:{form:{},name:'confirm_void'}});assert.equal(ready.checked,true);
handlers['[data-pick-none]']();assert.equal(button.disabled,true);
handlers.submit();assert.equal(button.disabled,true);assert.equal(button.textContent,'Submitting…');
vm.runInNewContext(script,{document:{querySelector:()=>null,getElementById:()=>null}});
console.log('Manifest form regressions passed.');
