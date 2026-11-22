const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["chunks/jspdf.es.min.DGUrouQF.js","app.DLipW_fg.js"])))=>i.map(i=>d[i]);
import{_ as x,u as A,a4 as R,ar as w,A as z}from"../app.DLipW_fg.js";function E(){try{return A().exportSettings}catch{return{scope:"all",totals:!0,pdf_orientation:"landscape",filename_date:!1,pdf_meta:!1}}}function L(t){return t.filename_date?`_${R().format("YYYY-MM-DD")}`:""}function h(t){return String(t.title??t.key??"")}function y(t,e){if(typeof t.exportValue=="function")return t.exportValue(e);const l=t.dataIndex||t.key,n=l?e[l]:"";return n==null?"":typeof n=="object"?JSON.stringify(n):n}function v(t){return typeof t=="string"?w(t):t}function f(t){return t.filter(e=>e.key!=="actions"&&e.key!=="image"&&e.exportable!==!1)}function I(t,e,l,n="Total"){const o=f(t);return o.some(r=>r.sum)?o.map((r,u)=>{if(r.sum){const i=r.dataIndex??r.key,s=e.reduce((p,a)=>p+(Number(a==null?void 0:a[i])||0),0);return l(r,s)}return u===0?n:""}):null}const _=/[֐-׿؀-ۿ܀-ݏݐ-ݿࢠ-ࣿיִ-﷿ﹰ-﻿]/;function D(t,e,l){if(_.test(String(t??"")))return!0;const n=f(e);return n.some(o=>_.test(h(o)))?!0:l.some(o=>n.some(r=>_.test(String(y(r,o)??""))))}function j(t,e,l,{subtitle:n=[],landscape:o=!0,foot:r=null,rtl:u=!1}={}){const i=f(e),s=a=>String(a??"").replace(/[&<>"]/g,d=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"})[d]),p=Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(a=>a.outerHTML).join(`
`);return`<!doctype html><html${u?' dir="rtl"':""}><head>
    <base href="${window.location.origin}/" />
    <title>${s(t)}</title>
    ${p}
    <style>
      @page { size: A4 ${o?"landscape":"portrait"}; margin: 0.3cm; }
      @media print { body, body * { visibility: visible !important; } }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 6px; font-size: 14px; }
      .print-sub { font-size: 11px; line-height: 1.5; margin-bottom: 10px; }
      table { width: 100%; border-collapse: collapse; }
      /* Cells may carry newlines (a variant product stacks one value per line);
         without pre-line the browser would collapse them onto one row. */
      th, td { border: 1px solid #ddd; padding: 6px 8px; font-size: 10px; text-align: ${u?"right":"left"}; white-space: pre-line; }
      th { background: #f5f5f5; font-weight: bold; }
      tr:nth-child(even) { background: #f9f9f9; }
    </style></head><body>
    <div class="print-header">${s(t)}</div>
    ${n.length?`<div class="print-sub">${n.map(s).join(" &nbsp;·&nbsp; ")}</div>`:""}
    <table>
      <thead><tr>${i.map(a=>`<th>${s(h(a))}</th>`).join("")}</tr></thead>
      <tbody>${l.map(a=>`<tr>${i.map(d=>`<td>${s(y(d,a))}</td>`).join("")}</tr>`).join("")}</tbody>
      ${r?`<tfoot><tr>${r.map(a=>`<td style="font-weight:bold;background:#f0f0f0">${s(a)}</td>`).join("")}</tr></tfoot>`:""}
    </table></body></html>`}const m="Vazirmatn",F="/fonts/Vazirmatn-Bold.ttf",O=["ar","fa","ur","he"];function Y(t){try{return t.addFont(F,m,"normal"),t.addFont(F,m,"bold"),t.setFont(m,"normal"),m}catch{return"helvetica"}}function C(){return typeof document>"u"?!1:document.documentElement.dir==="rtl"?!0:O.includes(String(document.documentElement.lang||"").slice(0,2))}async function M(t,e,l,{foot:n=null}={}){const o=await x(()=>import("./xlsx.BGhuli8m.js"),[]),r=f(e),u=[r.map(h),...l.map(p=>r.map(a=>v(y(a,p)))),...n?[n.map(v)]:[]],i=o.utils.aoa_to_sheet(u),s=o.utils.book_new();o.utils.book_append_sheet(s,i,"Report"),o.writeFile(s,`${t}${L(E())}.xlsx`)}async function H(t,e,l,{landscape:n=null,foot:o=null}={}){var T;const[{jsPDF:r},u]=await Promise.all([x(()=>import("./jspdf.es.min.DGUrouQF.js"),__vite__mapDeps([0,1])),x(()=>import("./jspdf.plugin.autotable.CxxEjV4y.js"),[])]),i=u.default||u.autoTable,s=f(e),p=E(),a=n??p.pdf_orientation!=="portrait",d=new r({orientation:a?"landscape":"portrait"}),b=Y(d),g=C(),$=g?d.internal.pageSize.getWidth()-14:14,S=g?{align:"right"}:void 0;d.setFontSize(14),d.text(String(t),$,15,S);let P=22;if(p.pdf_meta){let c="";try{c=((T=A().user)==null?void 0:T.company)||""}catch{}d.setFontSize(9),d.setTextColor(120),d.text(`${c?c+" — ":""}${R().format("YYYY-MM-DD HH:mm")}`,$,21,S),d.setTextColor(0),P=26}i(d,{head:[s.map(h)],body:l.map(c=>s.map(k=>w(y(k,c)))),startY:P,styles:{fontSize:8,font:b,halign:g?"right":"left"},headStyles:{fillColor:[109,40,217],font:b,fontStyle:"bold"},...o?{foot:[o.map(w)],footStyles:{fillColor:[240,240,240],textColor:20,fontStyle:"bold",font:b},showFoot:"lastPage"}:{}}),d.save(`${String(t).replace(/\s+/g,"_")}${L(p)}.pdf`)}function N(t,e,l,{subtitle:n=[],landscape:o=!0,win:r=null,foot:u=null}={}){const i=r||window.open("","_blank");if(!i){window.alert(z("Allow_Popups_To_Print","Please allow popups to print"));return}const s=D(t,e,l);i.document.open(),i.document.write(j(t,e,l,{subtitle:n,landscape:o,foot:u,rtl:s})),i.document.close(),i.focus(),setTimeout(()=>{i.print(),i.close()},400)}export{H as a,E as b,M as e,C as i,N as p,I as t,Y as u};
