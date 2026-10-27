/** Exercises the rendered selection script when a refresh form precedes the parcel form. */
const {execFileSync} = require('node:child_process');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const script = execFileSync('php', [__dirname + '/manifest-visibility.php', '--script'], {encoding:'utf8'});
const handlers = {};
const picks = [{checked:false}];
const ready = {checked:false};
const button = {};
const count = {};
const form = {
 elements:{ready}, querySelectorAll:()=>picks,
 querySelector: selector => selector === '[data-finish-submit]' ? button : selector === '[data-pick-count]' ? count : {addEventListener:(event,fn)=>{handlers[selector]=fn;}},
 addEventListener:(event,fn)=>{handlers[event]=fn;}
};
const box = {querySelector:selector=>selector === 'input[name=pickup_day]' ? {form} : {elements:{}}};
vm.runInNewContext(script,{document:{querySelector:()=>box}});
assert.equal(button.disabled,true);
handlers['[data-pick-all]']();
assert.equal(picks[0].checked,true);
assert.equal(button.disabled,true);
ready.checked=true; handlers.change({target:ready});
assert.equal(button.disabled,false);
handlers['[data-pick-none]']();
assert.equal(button.disabled,true);
vm.runInNewContext(script,{document:{querySelector:()=>({querySelector:()=>null})}});
console.log('Manifest form regressions passed.');
