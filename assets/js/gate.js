(() => {
  'use strict';
  const cfg = window.PESM_GATE_CONFIG;
  if (!cfg) return;
  document.querySelectorAll('.pesm-gate').forEach(el => {
    const form=el.querySelector('form'), message=el.querySelector('.pesm-gate-status');
    const start=el.querySelector('.pesm-start-camera'), stop=el.querySelector('.pesm-stop-camera');
    const video=el.querySelector('video'), placeholder=el.querySelector('.pesm-gate-camera-placeholder'); let stream=null, active=false, detector=null, busy=false;
    const cleanup=() => {if(placeholder)placeholder.hidden=false;active=false;stream?.getTracks().forEach(t=>t.stop());stream=null;video.srcObject=null;video.hidden=true;start.disabled=false;stop.disabled=true;};
    const setBusy=value=>{busy=value;el.setAttribute('aria-busy',String(value));el.querySelectorAll('form button').forEach(button=>button.disabled=value);};
    async function scan(text) {
      if (busy) return; setBusy(true);
      try {
        const response=await fetch(window.PESM_restURL('gate/check-in',cfg.apiRoot),{
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},
          body:JSON.stringify({qr_payload:text,gate:form.elements.gate.value})
        });
        const data=await response.json();
        if(!response.ok) throw Error(data.message || window.PESM_I18N.text("ثبت ورود ناموفق بود."));
        message.textContent=(window.PESM_I18N.text("ورود موفق: ") + String(data.ticket_number)); cleanup();
      } catch(e) {message.textContent=e.message;}
      finally {setBusy(false);}
    }
    form.addEventListener('submit', e=>{e.preventDefault();scan(form.elements.qr_payload.value.trim());});
    const numberForm=el.querySelector('.pesm-gate-number-form');
    async function manual(action) {
      if (busy || !numberForm.reportValidity()) return; setBusy(true);
      try {
        const response=await fetch(window.PESM_restURL(action,cfg.apiRoot),{
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},
          body:JSON.stringify({ticket_number:numberForm.elements.ticket_number.value.trim().toUpperCase(),
            gate:numberForm.elements.gate.value})
        });
        const data=await response.json().catch(()=>({}));
        if (!response.ok) throw Error(data.message||window.PESM_I18N.text("عملیات انجام نشد."));
        message.textContent=action==='gate/lookup'
          ? (window.PESM_I18N.text("شماره ") + String(data.ticket_number) + window.PESM_I18N.text("، جلسه ") + String(data.session_id) + window.PESM_I18N.text("، وضعیت: ") + String(window.PESM_I18N.status(data.status)))
          : (window.PESM_I18N.text("ورود دستی ثبت شد: ") + String(data.ticket_number));
      } catch(e) {message.textContent=e.message;}
      finally {setBusy(false);}
    }
    numberForm.addEventListener('submit',e=>{e.preventDefault();manual('gate/manual-check-in');});
    numberForm.querySelector('.pesm-gate-lookup').addEventListener('click',()=>manual('gate/lookup'));

    start.addEventListener('click', async ()=>{
      if(active) return;
      if(!navigator.mediaDevices?.getUserMedia || !('BarcodeDetector' in window)) {
        message.textContent=window.PESM_I18N.text("اسکن دوربینی در این مرورگر موجود نیست؛ می‌توانید کد را دستی وارد کنید.");return;
      }
      start.disabled=true;
      try {
        detector=new BarcodeDetector({formats:['qr_code']});
        stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});
        video.srcObject=stream;video.hidden=false;if(placeholder)placeholder.hidden=true;await video.play();active=true;stop.disabled=false;
        const loop=async()=>{if(!active)return;try{const found=await detector.detect(video);
          if(found.length){form.elements.qr_payload.value=found[0].rawValue;await scan(found[0].rawValue);}}
          catch(_){} if(active)requestAnimationFrame(loop);};loop();
      } catch(e){message.textContent=window.PESM_I18N.text("دسترسی به دوربین ممکن نیست.");cleanup();}
    });
    stop.addEventListener('click',cleanup);
    window.addEventListener('pagehide',cleanup,{once:true});
  });
})();
