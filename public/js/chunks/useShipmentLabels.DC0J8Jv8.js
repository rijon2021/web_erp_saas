import{J as h,x as b,G as f,Z as m}from"../app.DLipW_fg.js";import{J as u}from"./JsBarcode.Bz80JofR.js";import{e as g}from"./courierShared.CD4BGzwb.js";const t=e=>String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;");function v(e){if(!e)return"";const o=document.createElementNS("http://www.w3.org/2000/svg","svg");try{u(o,String(e),{format:"CODE128",height:56,margin:0,fontSize:14,width:2})}catch{return`<div class="tn">${t(e)}</div>`}return o.setAttribute("style","max-width:100%;height:auto"),o.outerHTML}function w(e,o,r){var s,i,n,d,c,l,p;const a=Number(e.cod_amount)>0;return`
  <section class="label">
    <header>
      <div class="courier">${t(e.courier_name)}</div>
      <div class="ref">${t(o("Cr_Order"))}: ${t(e.sale_ref)}<br>${t(e.booked_at)}</div>
    </header>
    <div class="barcode">${v(e.tracking_number)}</div>
    <div class="box to">
      <div class="cap">${t(o("Cr_Ship_To"))}</div>
      <div class="name">${t((s=e.to)==null?void 0:s.name)}</div>
      <div>${t((i=e.to)==null?void 0:i.phone)}</div>
      <div>${t((n=e.to)==null?void 0:n.address)}</div>
      ${(d=e.to)!=null&&d.city?`<div class="city">${t(e.to.city)}</div>`:""}
    </div>
    <div class="row">
      <div class="box cod ${a?"due":""}">
        <div class="cap">${t(o(a?"Cr_Collect_COD":"Cr_Prepaid"))}</div>
        <div class="amount">${a?t(r(e.cod_amount)):"—"}</div>
      </div>
      <div class="box meta">
        <div>${t(o("Cr_Weight"))}: ${t(e.weight??"—")} kg</div>
        <div>${t(o("Cr_Pieces"))}: ${t(e.pieces??1)}</div>
        ${e.allow_open_parcel?`<div>${t(o("Cr_Open_Allowed"))}</div>`:""}
      </div>
    </div>
    ${e.items?`<div class="box items"><div class="cap">${t(o("Cr_Contents"))}</div>${t(e.items)}</div>`:""}
    ${e.notes?`<div class="box items"><div class="cap">${t(o("Note"))}</div>${t(e.notes)}</div>`:""}
    <div class="box from">
      <div class="cap">${t(o("Cr_From"))}</div>
      <div class="name">${t((c=e.from)==null?void 0:c.name)}</div>
      <div>${t((l=e.from)==null?void 0:l.phone)}</div>
      <div>${t((p=e.from)==null?void 0:p.address)}</div>
    </div>
  </section>`}const y=`
  @page { size: 100mm 150mm; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 11px; }
  .label { width: 100mm; height: 150mm; padding: 4mm; page-break-after: always; display: flex; flex-direction: column; gap: 2mm; overflow: hidden; }
  .label:last-child { page-break-after: auto; }
  header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 2mm; }
  .courier { font-size: 16px; font-weight: 700; }
  .ref { text-align: right; font-size: 10px; }
  .barcode { text-align: center; padding: 1mm 0; }
  .tn { font-size: 18px; font-weight: 700; letter-spacing: 1px; }
  .box { border: 1px solid #000; padding: 2mm; }
  .cap { font-size: 9px; text-transform: uppercase; font-weight: 700; margin-bottom: 1mm; }
  .name { font-size: 14px; font-weight: 700; }
  .city { font-size: 14px; font-weight: 700; margin-top: 1mm; }
  .row { display: flex; gap: 2mm; }
  .row .box { flex: 1; }
  .cod.due { background: #000; color: #fff; }
  .amount { font-size: 18px; font-weight: 700; }
  .items { font-size: 10px; max-height: 18mm; overflow: hidden; }
  .from { margin-top: auto; font-size: 10px; }
`;function $(e,{t:o,money:r}){const a=window.open("","_blank","width=480,height=720");if(!a)return!1;const s=e.map(i=>w(i,o,r)).join("");return a.document.open(),a.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${t(o("Cr_Shipping_Labels"))}</title><style>${y}</style></head><body>${s}</body></html>`),a.document.close(),setTimeout(()=>{a.focus(),a.print()},300),!0}function k(){const{t:e}=h(),{money:o}=b();function r(i){if(i.type==="url"&&i.url)window.open(i.url,"_blank","noopener");else if(i.type==="pdf"&&i.data){const n=Uint8Array.from(atob(i.data),c=>c.charCodeAt(0)),d=URL.createObjectURL(new Blob([n],{type:"application/pdf"}));window.open(d,"_blank","noopener"),setTimeout(()=>URL.revokeObjectURL(d),6e4)}else if(i.type==="image"&&i.data){const n=/^image\/(gif|png|jpeg)$/.test(i.mime)?i.mime:"image/gif",c=`<!doctype html><html><head><title>${String(e("Label")).replace(/[<&>]/g,"")}</title><style>@page{size:4in 6in;margin:0}html,body{margin:0}img{display:block;width:4in;height:auto}</style></head><body><img src="data:${n};base64,${i.data}" onload="if(this.naturalWidth>this.naturalHeight){this.style.width='6in';this.style.transformOrigin='top left';this.style.transform='translateX(4in) rotate(90deg)'}setTimeout(function(){window.print()},200)"></body></html>`,l=URL.createObjectURL(new Blob([c],{type:"text/html"}));window.open(l,"_blank")||m.warning(e("Cr_Popup_Blocked")),setTimeout(()=>URL.revokeObjectURL(l),6e4)}else i.labels&&i.labels.length&&($(i.labels,{t:e,money:o})||m.warning(e("Cr_Popup_Blocked")))}async function a(i){try{r(await f.get(`couriers/shipments/${i}/label`))}catch(n){m.error(g(n,e("Failed")))}}async function s(i){if(i.length)try{r(await f.post("couriers/shipments/labels",{ids:i}))}catch(n){m.error(g(n,e("Failed")))}}return{printOne:a,printMany:s}}export{k as u};
