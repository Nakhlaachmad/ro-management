(() => {
 const dialog=document.querySelector('#viewer'),image=document.querySelector('#image'),status=document.querySelector('#status'),buttons=[...document.querySelectorAll('.open-document')];let index=0,zoom=1,angle=0,focus;
 const transform=()=>image.style.transform='scale('+zoom+') rotate('+angle+'deg)';
 const reset=()=>{zoom=1;angle=0;transform();};
 const show=i=>{index=(i+buttons.length)%buttons.length;reset();status.textContent='Memuat '+buttons[index].dataset.label+'…';image.hidden=true;image.src=buttons[index].dataset.url;};
 buttons.forEach((button,i)=>button.addEventListener('click',()=>{focus=button;dialog.showModal();show(i);}));
 image.onload=()=>{image.hidden=false;status.textContent=buttons[index].dataset.label;};image.onerror=()=>{image.hidden=true;status.textContent='Dokumen tidak tersedia atau akses ditolak.';};
 document.querySelector('#prev').onclick=()=>show(index-1);document.querySelector('#next').onclick=()=>show(index+1);
 document.querySelector('#zoom-in').onclick=()=>{zoom=Math.min(zoom+.25,4);transform();};document.querySelector('#zoom-out').onclick=()=>{zoom=Math.max(zoom-.25,.25);transform();};
 document.querySelector('#rotate-left').onclick=()=>{angle-=90;transform();};document.querySelector('#rotate-right').onclick=()=>{angle+=90;transform();};
 document.querySelector('#fit').onclick=reset;document.querySelector('#reset').onclick=reset;
 document.querySelector('#fullscreen').onclick=()=>{if(document.fullscreenElement)document.exitFullscreen();else dialog.requestFullscreen?.();};
 document.querySelector('#close').onclick=()=>dialog.close();
 dialog.addEventListener('close',()=>{image.removeAttribute('src');focus?.focus();if(document.fullscreenElement)document.exitFullscreen();});
})();
