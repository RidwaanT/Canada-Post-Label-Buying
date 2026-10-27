/** Explicit selection only; preserves readiness and double-submit protection. */
(function () {
 const box=document.querySelector('.wlp-manifests');
 const form=document.getElementById('wlp-manifest-selection');
 if (!box || !form) return;
 const picks=[...box.querySelectorAll('input[name="parcels[]"]:not(:disabled)')];
 const ready=form.elements.ready, button=box.querySelector('[data-finish-submit]');
 if (!ready || !button) return;
 const counter=box.querySelector('[data-pick-count]');
 function update() { const count=picks.filter(p=>p.checked).length; counter.textContent=count+' parcel'+(count===1?'':'s')+' selected';button.disabled=!count||!ready.checked; }
 box.querySelector('[data-pick-all]').addEventListener('click',()=>{picks.forEach(p=>p.checked=true);ready.checked=false;update();});
 box.querySelector('[data-pick-none]').addEventListener('click',()=>{picks.forEach(p=>p.checked=false);ready.checked=false;update();});
 box.addEventListener('change',e=>{if(e.target.form!==form)return;if(e.target!==ready)ready.checked=false;update();});
 form.addEventListener('submit',()=>{button.disabled=true;button.textContent='Submitting…';});
 update();
})();
