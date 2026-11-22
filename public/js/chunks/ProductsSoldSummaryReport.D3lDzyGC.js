import{s as mt,J as vt,x as yt,u as ft,r as p,a4 as Z,o as bt,j as y,c as d,e as h,m as n,p as r,z as u,F as U,q as m,l as o,A as a,C as j,D as K,y as P,k as s,L as ht,M as gt,G as xt,Z as X,H,N as wt,T as $t}from"../app.DLipW_fg.js";import{_ as kt}from"./PageHeader.B9TEFwzc.js";import{_ as St}from"./DateRangePicker.MxWhvZ0p.js";import{_ as Tt}from"./ViewCurrencySelect.1O1OEwFn.js";import{e as Pt,a as Ct}from"./exporters.CkS84lWl.js";import{P as Dt}from"./PrinterOutlined.VVK8GLzk.js";import{F as qt}from"./FileExcelOutlined.71yivI6G.js";import{F as zt}from"./FilePdfOutlined.BM3_Cypz.js";import{N as Ut}from"./NumberOutlined.v_hnGFk_.js";import{D as It}from"./DollarOutlined.CRw5AkWT.js";const Et={class:"page"},Ot={class:"kpi-inner"},Nt={class:"kpi-text"},Rt={class:"kpi-value"},Gt={key:1,class:"summary-table-wrap"},Yt={class:"summary-table"},Ft={style:{width:"44%"}},At={class:"num",style:{width:"14%"}},Mt={class:"num",style:{width:"20%"}},Bt={class:"num",style:{width:"22%"}},Vt={class:"group-row"},Lt={colspan:"4"},Qt={class:"num"},jt={class:"num"},Ht={key:0},Jt={class:"num"},Wt={class:"subtotal-row"},Zt={class:"num bold"},Kt={class:"num bold"},Xt={class:"num bold"},te={class:"grand-row"},ee={class:"num bold"},ae={class:"num bold"},le={class:"num bold"},oe={__name:"ProductsSoldSummaryReport",setup(se){const{t:v}=vt(),{money:C,number:rt}=yt(),J=ft(),q=p([Z().startOf("day"),Z()]),R=p(void 0),G=p(void 0),Y=p(void 0),F=p(void 0),A=p(""),W=p(80),$=p(!0),M=p(""),I=p([]),f=p({quantity:0,total:0,items:0}),i=p({}),z=p({}),tt=p([]),et=p([]),at=p([]),B=p("category"),it=H(()=>[{value:"category",label:`${a("Group_by","Group by")}: ${v("Categorie")}`},{value:"warehouse",label:`${a("Group_by","Group by")}: ${v("warehouse")}`}]),ut=H(()=>[{value:"completed",label:a("Completed","Completed")},{value:"pending",label:a("Pending","Pending")},{value:"ordered",label:a("Ordered","Ordered")}]),lt=e=>(e||[]).map(t=>({value:t.id,label:t.name})),dt=H(()=>(et.value||[]).map(e=>({value:e.id,label:e.username})));function k(e){const t=Number(e||0);return Number.isInteger(t)?String(t):t.toFixed(2)}function V(e){return e.group_id!==null?e.group_name:i.value.group_by==="warehouse"?a("Unassigned","Unassigned"):a("Uncategorized","Uncategorized")}const ct=H(()=>[{key:"items",label:a("Items_Sold","Items sold"),value:f.value.items,icon:wt,color:"#1677ff",tint:"rgba(22, 119, 255, 0.12)"},{key:"qty",label:a("Total_Qty","Total qty"),value:k(f.value.quantity),icon:Ut,color:"#13c2c2",tint:"rgba(19, 194, 194, 0.12)"},{key:"total",label:a("Grand_Total","Grand total"),value:C(f.value.total),icon:It,color:"#6d28d9",tint:"rgba(109, 40, 217, 0.12)"},{key:"trans",label:`${a("Transactions","Transactions")} / ${a("Cust_Count","Customers")}`,value:`${i.value.transaction_count??0} / ${i.value.customer_count??0}`,icon:$t,color:"#22c55e",tint:"rgba(34, 197, 94, 0.12)"}]),pt=()=>{var e,t;return{group_by:B.value,...(e=q.value)!=null&&e[0]?{from:q.value[0].format("YYYY-MM-DD")}:{},...(t=q.value)!=null&&t[1]?{to:q.value[1].format("YYYY-MM-DD")}:{},...R.value?{warehouse_id:R.value}:{},...G.value?{user_id:G.value}:{},...Y.value?{category_id:Y.value}:{},...F.value?{statut:F.value}:{},...A.value?{search:A.value}:{}}};async function S(){$.value=!0;try{const e=await xt.get("report/products_sold_summary",pt());I.value=Array.isArray(e.groups)?e.groups:[],f.value=e.totals||{quantity:0,total:0,items:0},i.value=e.meta||{},z.value=e.company||{},tt.value=e.warehouses||[],et.value=e.users||[],at.value=e.categories_list||[]}catch(e){X.error((e==null?void 0:e.message)||v("Error"))}finally{$.value=!1}}function ot(){const e=[];return I.value.forEach(t=>{t.items.forEach(c=>e.push({group_name:V(t),product_code:c.product_code||"",product_name:c.product_name,unit:c.unit||"",quantity:c.quantity,unit_price:c.unit_price===null?"":c.unit_price,total:c.total})),e.push({group_name:V(t),product_code:"",product_name:a("Sub_Total","Sub Total"),unit:"",quantity:t.sub_total_quantity,unit_price:"",total:t.sub_total})}),e.length&&e.push({group_name:"",product_code:"",product_name:a("Grand_Total","Grand Total"),unit:"",quantity:f.value.quantity,unit_price:"",total:f.value.total}),e}const st=()=>[{title:B.value==="warehouse"?v("warehouse"):v("Categorie"),dataIndex:"group_name"},{title:v("Code"),dataIndex:"product_code"},{title:v("ProductName"),dataIndex:"product_name"},{title:a("Unit","Unit"),dataIndex:"unit"},{title:v("Quantity"),dataIndex:"quantity"},{title:a("Unit_Price","Unit price"),dataIndex:"unit_price",exportValue:e=>e.unit_price===""?"":C(e.unit_price)},{title:v("Total"),dataIndex:"total",exportValue:e=>C(e.total)}];async function nt(e){if(J.canExportProducts){M.value=e;try{const t=`products-sold-summary_${i.value.from}_${i.value.to}`;e==="excel"?await Pt(t,st(),ot()):await Ct(a("products_sold_summary","Products Sold Summary"),st(),ot())}catch(t){X.error((t==null?void 0:t.message)||v("Export_Failed"))}finally{M.value=""}}}function _t(){if(!J.canExportProducts)return;const e=window.open("","_blank","width=420,height=640");if(!e){X.error(v("Allow_Popups_To_Print"));return}const t=_=>String(_??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"),c=Number(W.value)||80,T=_=>rt(_),E=i.value.day_status==="open"?a("Day_is_still_opened","Day is still opened"):i.value.day_status==="closed"?a("Day_is_closed","Day is closed"):"",w=(_,x)=>`<div class="kv"><span class="k">${t(_)}</span><span class="s">:</span><span class="v">${t(x)}</span></div>`;let g="";I.value.forEach(_=>{g+=`<div class="cat">${t(V(_))}</div>`,g+='<table class="items">',_.items.forEach(x=>{const l=x.unit_price===null?k(x.quantity):`${k(x.quantity)} x ${T(x.unit_price)}`;g+=`<tr>
        <td class="name">${t(x.product_name)}</td>
        <td class="qty">${t(l)}</td>
        <td class="amt">${t(T(x.total))}</td>
      </tr>`}),g+=`<tr class="sub">
        <td class="name">${t(a("Sub_Total","Sub Total"))}</td>
        <td class="qty">${t(k(_.sub_total_quantity))}</td>
        <td class="amt">${t(T(_.sub_total))}</td>
      </tr>`,g+="</table>"});const D=i.value.from===i.value.to?i.value.from:`${i.value.from} — ${i.value.to}`,L=Z().format("DD-MM-YYYY HH:mm:ss"),O=e.document;O.open(),O.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>${t(a("Day_Report","Day Report"))}</title>
    <style>
      @page { size: ${c}mm auto; margin: 0; }
      @media print { body, body * { visibility: visible !important; } }
      * { box-sizing: border-box; }
      body {
        width: ${c}mm;
        margin: 0;
        padding: 3mm 2mm;
        font-family: "Courier New", Courier, monospace;
        font-size: 11px;
        line-height: 1.35;
        color: #000;
        background: #fff;
      }
      .center { text-align: center; }
      .company { font-weight: bold; font-size: 12px; }
      .rule { border-top: 1px dashed #000; margin: 4px 0; }
      .title { font-weight: bold; font-size: 14px; margin: 4px 0 6px; }
      .kv { display: flex; }
      .kv .k { flex: 0 0 42%; text-transform: uppercase; }
      .kv .s { flex: 0 0 4%; }
      .kv .v { flex: 1 1 auto; }
      .banner { text-align: center; font-weight: bold; text-transform: uppercase; margin: 2px 0; }
      .section { font-weight: bold; text-transform: uppercase; margin: 6px 0 2px; }
      .cat { font-weight: bold; text-decoration: underline; margin-top: 6px; }
      table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
      table.items td { vertical-align: top; padding: 1px 0; word-wrap: break-word; }
      td.name { width: 58%; }
      td.qty  { width: 12%; text-align: right; padding-right: 2mm; }
      td.amt  { width: 30%; text-align: right; }
      tr.sub td { border-top: 1px solid #000; font-weight: bold; padding-top: 2px; }
      .grand { display: flex; justify-content: space-between; font-weight: bold;
               font-size: 12px; border-top: 1px solid #000; border-bottom: 1px solid #000;
               padding: 3px 0; margin-top: 6px; }
      .foot { text-align: center; margin-top: 8px; font-size: 10px; }
    </style>
  </head>
  <body>
    <div class="center company">${t(z.value.name)}</div>
    <div class="center">${t(z.value.full_address||z.value.address)}</div>
    <div class="center">${t(z.value.email)}</div>
    <div class="center">${t(z.value.phone)}</div>

    <div class="title">${t(a("Day_Report","Day Report"))}</div>

    ${w(a("Till","Till"),i.value.till||v("All"))}
    ${w(a("Sales_Person","Sales person"),i.value.sales_person||v("All"))}
    ${w(a("Cust_Count","Cust. count"),i.value.customer_count??0)}
    ${w(a("Transactions","Transactions"),i.value.transaction_count??0)}
    ${w(a("Trans_Date","Trans. date"),D)}
    ${w(a("Printed_On","Printed on"),L)}
    ${w(a("Printed_By","Printed by"),i.value.printed_by||"")}

    ${E?`<div class="rule"></div><div class="banner">${t(E)}</div>`:""}
    <div class="rule"></div>

    <div class="section">${t(v("Sales"))}</div>
    ${g||'<div class="center">—</div>'}

    <div class="grand">
      <span>${t(a("Grand_Total","Grand Total"))}</span>
      <span>${t(T(f.value.total))}</span>
    </div>

    <div class="foot">${t(a("Items_Sold","Items sold"))}: ${t(f.value.items)} &nbsp;·&nbsp; ${t(a("Total_Qty","Total qty"))}: ${t(k(f.value.quantity))}</div>
  </body>
</html>`),O.close(),e.focus();let Q=!1;const N=()=>{if(!Q){Q=!0;try{e.close()}catch{}}};try{e.onafterprint=N}catch{}setTimeout(()=>{try{e.print()}catch{}setTimeout(N,6e4)},400)}return bt(S),(e,t)=>{const c=y("a-select"),T=y("a-button"),E=y("a-space"),w=y("a-input-search"),g=y("a-card"),D=y("a-typography-text"),L=y("a-spin"),O=y("a-col"),Q=y("a-row"),N=y("a-tag"),_=y("a-empty"),x=y("a-tooltip");return d(),h("div",Et,[n(kt,{title:u(a)("products_sold_summary","Products Sold Summary"),breadcrumb:[e.$t("Reports"),u(a)("products_sold_summary","Products Sold Summary")]},{actions:r(()=>[n(E,{wrap:""},{default:r(()=>[n(Tt),u(J).canExportProducts?(d(),h(U,{key:0},[n(c,{value:W.value,"onUpdate:value":t[0]||(t[0]=l=>W.value=l),style:{width:"100px"},options:[{value:58,label:"58 mm"},{value:80,label:"80 mm"},{value:88,label:"88 mm"}]},null,8,["value"]),n(T,{type:"primary",disabled:$.value,onClick:_t},{icon:r(()=>[n(u(Dt))]),default:r(()=>[m(" "+o(u(a)("Print_Receipt","Print Receipt")),1)]),_:1},8,["disabled"]),n(T,{loading:M.value==="excel",disabled:$.value,onClick:t[1]||(t[1]=l=>nt("excel"))},{icon:r(()=>[n(u(qt))]),default:r(()=>[m(" "+o(e.$t("Export_Excel")),1)]),_:1},8,["loading","disabled"]),n(T,{loading:M.value==="pdf",disabled:$.value,onClick:t[2]||(t[2]=l=>nt("pdf"))},{icon:r(()=>[n(u(zt))]),default:r(()=>[m(" "+o(e.$t("Export_PDF")),1)]),_:1},8,["loading","disabled"])],64)):j("",!0)]),_:1})]),_:1},8,["title","breadcrumb"]),n(g,{size:"small",style:{"margin-bottom":"16px"}},{default:r(()=>[n(E,{wrap:""},{default:r(()=>[n(St,{value:q.value,"onUpdate:value":t[3]||(t[3]=l=>q.value=l),"allow-clear":"",onChange:S},null,8,["value"]),n(c,{value:B.value,"onUpdate:value":t[4]||(t[4]=l=>B.value=l),style:{width:"190px"},options:it.value,onChange:S},null,8,["value","options"]),n(c,{value:R.value,"onUpdate:value":t[5]||(t[5]=l=>R.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:u(a)("Till","Till"),options:lt(tt.value),onChange:S},null,8,["value","placeholder","options"]),n(c,{value:G.value,"onUpdate:value":t[6]||(t[6]=l=>G.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:u(a)("Sales_Person","Sales person"),options:dt.value,onChange:S},null,8,["value","placeholder","options"]),n(c,{value:Y.value,"onUpdate:value":t[7]||(t[7]=l=>Y.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:e.$t("Categorie"),options:lt(at.value),onChange:S},null,8,["value","placeholder","options"]),n(c,{value:F.value,"onUpdate:value":t[8]||(t[8]=l=>F.value=l),style:{width:"150px"},"allow-clear":"",placeholder:u(a)("Sale_Status","Sale status"),options:ut.value,onChange:S},null,8,["value","placeholder","options"]),n(w,{value:A.value,"onUpdate:value":t[9]||(t[9]=l=>A.value=l),style:{width:"220px"},"allow-clear":"",placeholder:e.$t("Search"),onSearch:S},null,8,["value","placeholder"])]),_:1})]),_:1}),n(Q,{gutter:[16,16],style:{"margin-bottom":"16px"}},{default:r(()=>[(d(!0),h(U,null,K(ct.value,l=>(d(),P(O,{key:l.key,xs:12,sm:12,md:6},{default:r(()=>[n(g,{size:"small",class:"kpi-card"},{default:r(()=>[s("div",Ot,[s("div",{class:"kpi-icon",style:ht({background:l.tint,color:l.color})},[(d(),P(gt(l.icon)))],4),s("div",Nt,[n(D,{type:"secondary",class:"kpi-label"},{default:r(()=>[m(o(l.label),1)]),_:2},1024),s("div",Rt,[$.value?(d(),P(L,{key:0,size:"small"})):(d(),h(U,{key:1},[m(o(l.value),1)],64))])])])]),_:2},1024)]),_:2},1024))),128))]),_:1}),n(g,{size:"small"},{title:r(()=>[m(o(u(a)("Day_Report","Day Report"))+" ",1),i.value.day_status==="open"?(d(),P(N,{key:0,color:"warning",style:{"margin-left":"8px"}},{default:r(()=>[m(o(u(a)("Day_is_still_opened","Day is still opened")),1)]),_:1})):i.value.day_status==="closed"?(d(),P(N,{key:1,style:{"margin-left":"8px"}},{default:r(()=>[m(o(u(a)("Day_is_closed","Day is closed")),1)]),_:1})):j("",!0)]),extra:r(()=>[n(D,{type:"secondary",style:{"font-size":"12px"}},{default:r(()=>[m(o(u(a)("Till","Till"))+": "+o(i.value.till||e.$t("All"))+" · "+o(u(a)("Sales_Person","Sales person"))+": "+o(i.value.sales_person||e.$t("All"))+" · "+o(i.value.from)+" → "+o(i.value.to),1)]),_:1})]),default:r(()=>[n(L,{spinning:$.value},{default:r(()=>[!I.value.length&&!$.value?(d(),P(_,{key:0,description:e.$t("NodataAvailable"),style:{padding:"48px 0"}},null,8,["description"])):(d(),h("div",Gt,[s("table",Yt,[s("thead",null,[s("tr",null,[s("th",Ft,o(e.$t("ProductName")),1),s("th",At,o(e.$t("Quantity")),1),s("th",Mt,o(u(a)("Unit_Price","Unit price")),1),s("th",Bt,o(e.$t("Total")),1)])]),(d(!0),h(U,null,K(I.value,l=>(d(),h("tbody",{key:l.group_id??"none"},[s("tr",Vt,[s("th",Lt,o(V(l)),1)]),(d(!0),h(U,null,K(l.items,b=>(d(),h("tr",{key:(l.group_id??0)+"-"+b.product_id+"-"+b.product_name},[s("td",null,[m(o(b.product_name)+" ",1),b.product_code?(d(),P(D,{key:0,type:"secondary",style:{display:"block","font-size":"12px"}},{default:r(()=>[m(o(b.product_code),1)]),_:2},1024)):j("",!0)]),s("td",Qt,[m(o(k(b.quantity))+" ",1),n(D,{type:"secondary",style:{"font-size":"12px"}},{default:r(()=>[m(o(b.unit),1)]),_:2},1024)]),s("td",jt,[b.unit_price===null?(d(),h("span",Ht,"—")):(d(),h(U,{key:1},[m(o(u(C)(b.unit_price)),1)],64)),b.price_varies?(d(),P(x,{key:2,title:u(a)("Sold_at_several_prices","Sold at several prices in this period; this is the weighted average.")},{default:r(()=>[n(D,{type:"secondary",style:{"font-size":"12px"}},{default:r(()=>[...t[10]||(t[10]=[m(" ~",-1)])]),_:1})]),_:1},8,["title"])):j("",!0)]),s("td",Jt,o(u(C)(b.total)),1)]))),128)),s("tr",Wt,[s("td",Zt,o(u(a)("Sub_Total","Sub Total")),1),s("td",Kt,o(k(l.sub_total_quantity)),1),t[11]||(t[11]=s("td",{class:"num"},null,-1)),s("td",Xt,o(u(C)(l.sub_total)),1)])]))),128)),s("tfoot",null,[s("tr",te,[s("td",ee,o(u(a)("Grand_Total","Grand Total")),1),s("td",ae,o(k(f.value.quantity)),1),t[12]||(t[12]=s("td",{class:"num"},null,-1)),s("td",le,o(u(C)(f.value.total)),1)])])])]))]),_:1},8,["spinning"])]),_:1})])}}},ye=mt(oe,[["__scopeId","data-v-562725b7"]]);export{ye as default};
