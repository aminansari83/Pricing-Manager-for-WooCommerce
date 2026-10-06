/* global wp */
// Accessible name-filter suggestions. Prices change only through the existing frozen preview.
window.APGProductSearch = (() => {
 'use strict';
 const { __ } = wp.i18n;
 let serial=0;
 function attach(field,fetchRows){
  const root=document.createElement('div'),list=document.createElement('div'),status=document.createElement('p');
  const key='apg-name-search-'+(++serial);root.className='apg-name-search';field.after(root);root.append(list,status);
  list.id=key;list.className='apg-search-options';list.setAttribute('role','listbox');list.setAttribute('aria-label',__('پیشنهاد نام محصول برای فیلتر','andiya-price-guard'));list.hidden=true;
  status.id=key+'-status';status.className='apg-help';status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  field.setAttribute('role','combobox');field.setAttribute('aria-autocomplete','list');field.setAttribute('aria-haspopup','listbox');field.setAttribute('aria-controls',key);field.setAttribute('aria-expanded','false');field.setAttribute('autocomplete','off');
  field.setAttribute('aria-describedby',[field.getAttribute('aria-describedby'),status.id].filter(Boolean).join(' '));
  let timer,controller,epoch=0,active=-1,rows=[],composing=false;
  function close(){list.hidden=true;field.setAttribute('aria-expanded','false');field.removeAttribute('aria-activedescendant');active=-1;}
  function cancel(){clearTimeout(timer);epoch++;controller?.abort();controller=null;list.removeAttribute('aria-busy');close();}
  function select(index){const row=rows[index];if(!row)return;cancel();field.value=row.name;status.textContent=__('عبارت نام انتخاب شد؛ تمام محصولات منطبق را در پیش‌نمایش بررسی کنید.','andiya-price-guard');field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));cancel();field.focus();}
  function highlight(index){const nodes=[...list.children];if(!nodes.length)return;active=(index+nodes.length)%nodes.length;nodes.forEach((n,i)=>n.setAttribute('aria-selected',i===active?'true':'false'));field.setAttribute('aria-activedescendant',nodes[active].id);nodes[active].scrollIntoView({block:'nearest'});}
  async function search(value,sequence){
   controller=new AbortController();list.setAttribute('aria-busy','true');status.textContent=__('در حال جست‌وجوی نام محصولات…','andiya-price-guard');
   try{
    const data=await fetchRows(value,controller.signal);
    if(sequence!==epoch||field.value.trim()!==value)return;
    rows=data.rows;list.replaceChildren();rows.forEach((row,i)=>{const n=document.createElement('div');n.id=key+'-'+i;n.className='apg-search-option';n.setAttribute('role','option');n.setAttribute('aria-selected','false');n.textContent=row.name+(row.sku?' / '+row.sku:'');n.addEventListener('mousedown',e=>e.preventDefault());n.addEventListener('click',()=>select(i));list.append(n);});
    list.hidden=!rows.length;field.setAttribute('aria-expanded',rows.length?'true':'false');
    status.textContent=rows.length?__('این فیلتر، تمام محصولات منطبق با عبارت نام را شامل می‌شود؛ تعداد نهایی را در پیش‌نمایش بررسی کنید.','andiya-price-guard'):__('نام محصولی پیدا نشد. عبارت کوتاه‌تری وارد کنید.','andiya-price-guard');
   }catch(e){if(sequence===epoch&&e.name!=='AbortError'){close();status.textContent=e.message;}}
   finally{if(sequence===epoch)list.removeAttribute('aria-busy');}
  }
  function schedule(){cancel();if(composing)return;const value=field.value.trim();if([...value].length<2){status.textContent=value?__('برای پیشنهاد نام، دست‌کم دو حرف وارد کنید.','andiya-price-guard'):'';return;}const sequence=epoch;timer=setTimeout(()=>search(value,sequence),350);}
  field.addEventListener('input',schedule);field.addEventListener('compositionstart',()=>{composing=true;cancel();});field.addEventListener('compositionend',()=>{composing=false;schedule();});
  field.addEventListener('keydown',e=>{if(e.key==='Escape'){cancel();status.textContent='';return;}if(list.hidden)return;if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();highlight(active+(e.key==='ArrowDown'?1:-1));}else if(e.key==='Enter'&&active>=0){e.preventDefault();select(active);}else if(e.key==='Tab')close();});
  field.addEventListener('blur',()=>{cancel();status.textContent='';});
  field.addEventListener('apg:search-reset',()=>{cancel();status.textContent='';});field.form?.addEventListener('reset',()=>{cancel();status.textContent='';});
  return {cancel};
 }
 return {attach};
})();
