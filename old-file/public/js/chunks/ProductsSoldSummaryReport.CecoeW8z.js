import{s as _t,u as mt,x as vt,r as p,$ as J,o as yt,j as v,c as d,e as x,m as n,p as r,q as m,l as o,A as u,a6 as a,F as E,B as W,y as C,k as s,D as ft,E as bt,z as K,G as ht,V as X,H as j,J as gt,T as xt}from"../app.CrKTReiH.js";import{_ as wt}from"./PageHeader.DrtEdF1M.js";import{_ as $t}from"./DateRangePicker.Bnrvfci8.js";import{_ as kt}from"./ViewCurrencySelect.CqpZIija.js";import{e as St,a as Tt}from"./exporters.BtLXvc1y.js";import{P as Ct}from"./PrinterOutlined.Cvsc_Byr.js";import{F as Dt}from"./FileExcelOutlined.WXaY1814.js";import{F as Pt}from"./FilePdfOutlined.rl8wcSEK.js";import{N as qt}from"./NumberOutlined.B9aUDjab.js";import{D as zt}from"./DollarOutlined.BCn_0kQI.js";const Ut={class:"page"},It={class:"kpi-inner"},Ot={class:"kpi-text"},Rt={class:"kpi-value"},Et={key:1,class:"summary-table-wrap"},Nt={class:"summary-table"},Gt={style:{width:"44%"}},Yt={class:"num",style:{width:"14%"}},Ft={class:"num",style:{width:"20%"}},At={class:"num",style:{width:"22%"}},Bt={class:"group-row"},Mt={colspan:"4"},Vt={class:"num"},Lt={class:"num"},Qt={key:0},jt={class:"num"},Ht={class:"subtotal-row"},Jt={class:"num bold"},Wt={class:"num bold"},Kt={class:"num bold"},Xt={class:"grand-row"},Zt={class:"num bold"},te={class:"num bold"},ee={class:"num bold"},ae={__name:"ProductsSoldSummaryReport",setup(le){const{t:y}=mt(),{money:D,number:nt}=vt(),q=p([J().startOf("day"),J()]),N=p(void 0),G=p(void 0),Y=p(void 0),F=p(void 0),A=p(""),H=p(80),$=p(!0),B=p(""),U=p([]),f=p({quantity:0,total:0,items:0}),i=p({}),z=p({}),Z=p([]),tt=p([]),et=p([]),M=p("category"),rt=j(()=>[{value:"category",label:`${a("Group_by","Group by")}: ${y("Categorie")}`},{value:"warehouse",label:`${a("Group_by","Group by")}: ${y("warehouse")}`}]),it=j(()=>[{value:"completed",label:a("Completed","Completed")},{value:"pending",label:a("Pending","Pending")},{value:"ordered",label:a("Ordered","Ordered")}]),at=e=>(e||[]).map(t=>({value:t.id,label:t.name})),ut=j(()=>(tt.value||[]).map(e=>({value:e.id,label:e.username})));function k(e){const t=Number(e||0);return Number.isInteger(t)?String(t):t.toFixed(2)}function V(e){return e.group_id!==null?e.group_name:i.value.group_by==="warehouse"?a("Unassigned","Unassigned"):a("Uncategorized","Uncategorized")}const dt=j(()=>[{key:"items",label:a("Items_Sold","Items sold"),value:f.value.items,icon:gt,color:"#1677ff",tint:"rgba(22, 119, 255, 0.12)"},{key:"qty",label:a("Total_Qty","Total qty"),value:k(f.value.quantity),icon:qt,color:"#13c2c2",tint:"rgba(19, 194, 194, 0.12)"},{key:"total",label:a("Grand_Total","Grand total"),value:D(f.value.total),icon:zt,color:"#6d28d9",tint:"rgba(109, 40, 217, 0.12)"},{key:"trans",label:`${a("Transactions","Transactions")} / ${a("Cust_Count","Customers")}`,value:`${i.value.transaction_count??0} / ${i.value.customer_count??0}`,icon:xt,color:"#22c55e",tint:"rgba(34, 197, 94, 0.12)"}]),ct=()=>{var e,t;return{group_by:M.value,...(e=q.value)!=null&&e[0]?{from:q.value[0].format("YYYY-MM-DD")}:{},...(t=q.value)!=null&&t[1]?{to:q.value[1].format("YYYY-MM-DD")}:{},...N.value?{warehouse_id:N.value}:{},...G.value?{user_id:G.value}:{},...Y.value?{category_id:Y.value}:{},...F.value?{statut:F.value}:{},...A.value?{search:A.value}:{}}};async function S(){$.value=!0;try{const e=await ht.get("report/products_sold_summary",ct());U.value=Array.isArray(e.groups)?e.groups:[],f.value=e.totals||{quantity:0,total:0,items:0},i.value=e.meta||{},z.value=e.company||{},Z.value=e.warehouses||[],tt.value=e.users||[],et.value=e.categories_list||[]}catch(e){X.error((e==null?void 0:e.message)||"Error")}finally{$.value=!1}}function lt(){const e=[];return U.value.forEach(t=>{t.items.forEach(c=>e.push({group_name:V(t),product_code:c.product_code||"",product_name:c.product_name,unit:c.unit||"",quantity:c.quantity,unit_price:c.unit_price===null?"":c.unit_price,total:c.total})),e.push({group_name:V(t),product_code:"",product_name:a("Sub_Total","Sub Total"),unit:"",quantity:t.sub_total_quantity,unit_price:"",total:t.sub_total})}),e.length&&e.push({group_name:"",product_code:"",product_name:a("Grand_Total","Grand Total"),unit:"",quantity:f.value.quantity,unit_price:"",total:f.value.total}),e}const ot=()=>[{title:M.value==="warehouse"?y("warehouse"):y("Categorie"),dataIndex:"group_name"},{title:y("Code"),dataIndex:"product_code"},{title:y("ProductName"),dataIndex:"product_name"},{title:a("Unit","Unit"),dataIndex:"unit"},{title:y("Quantity"),dataIndex:"quantity"},{title:a("Unit_Price","Unit price"),dataIndex:"unit_price",exportValue:e=>e.unit_price===""?"":D(e.unit_price)},{title:y("Total"),dataIndex:"total",exportValue:e=>D(e.total)}];async function st(e){B.value=e;try{const t=`products-sold-summary_${i.value.from}_${i.value.to}`;e==="excel"?await St(t,ot(),lt()):await Tt(a("products_sold_summary","Products Sold Summary"),ot(),lt())}catch(t){X.error((t==null?void 0:t.message)||"Export failed")}finally{B.value=""}}function pt(){const e=window.open("","_blank","width=420,height=640");if(!e){X.error("Please allow popups to print");return}const t=_=>String(_??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"),c=Number(H.value)||80,T=_=>nt(_),I=i.value.day_status==="open"?a("Day_is_still_opened","Day is still opened"):i.value.day_status==="closed"?a("Day_is_closed","Day is closed"):"",w=(_,g)=>`<div class="kv"><span class="k">${t(_)}</span><span class="s">:</span><span class="v">${t(g)}</span></div>`;let h="";U.value.forEach(_=>{h+=`<div class="cat">${t(V(_))}</div>`,h+='<table class="items">',_.items.forEach(g=>{const l=g.unit_price===null?k(g.quantity):`${k(g.quantity)} x ${T(g.unit_price)}`;h+=`<tr>
        <td class="name">${t(g.product_name)}</td>
        <td class="qty">${t(l)}</td>
        <td class="amt">${t(T(g.total))}</td>
      </tr>`}),h+=`<tr class="sub">
        <td class="name">${t(a("Sub_Total","Sub Total"))}</td>
        <td class="qty">${t(k(_.sub_total_quantity))}</td>
        <td class="amt">${t(T(_.sub_total))}</td>
      </tr>`,h+="</table>"});const P=i.value.from===i.value.to?i.value.from:`${i.value.from} — ${i.value.to}`,L=J().format("DD-MM-YYYY HH:mm:ss"),O=e.document;O.open(),O.write(`<!doctype html>
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

    ${w(a("Till","Till"),i.value.till||y("All"))}
    ${w(a("Sales_Person","Sales person"),i.value.sales_person||y("All"))}
    ${w(a("Cust_Count","Cust. count"),i.value.customer_count??0)}
    ${w(a("Transactions","Transactions"),i.value.transaction_count??0)}
    ${w(a("Trans_Date","Trans. date"),P)}
    ${w(a("Printed_On","Printed on"),L)}
    ${w(a("Printed_By","Printed by"),i.value.printed_by||"")}

    ${I?`<div class="rule"></div><div class="banner">${t(I)}</div>`:""}
    <div class="rule"></div>

    <div class="section">${t(y("Sales"))}</div>
    ${h||'<div class="center">—</div>'}

    <div class="grand">
      <span>${t(a("Grand_Total","Grand Total"))}</span>
      <span>${t(T(f.value.total))}</span>
    </div>

    <div class="foot">${t(a("Items_Sold","Items sold"))}: ${t(f.value.items)} &nbsp;·&nbsp; ${t(a("Total_Qty","Total qty"))}: ${t(k(f.value.quantity))}</div>
  </body>
</html>`),O.close(),e.focus();let Q=!1;const R=()=>{if(!Q){Q=!0;try{e.close()}catch{}}};try{e.onafterprint=R}catch{}setTimeout(()=>{try{e.print()}catch{}setTimeout(R,6e4)},400)}return yt(S),(e,t)=>{const c=v("a-select"),T=v("a-button"),I=v("a-space"),w=v("a-input-search"),h=v("a-card"),P=v("a-typography-text"),L=v("a-spin"),O=v("a-col"),Q=v("a-row"),R=v("a-tag"),_=v("a-empty"),g=v("a-tooltip");return d(),x("div",Ut,[n(wt,{title:u(a)("products_sold_summary","Products Sold Summary"),breadcrumb:[e.$t("Reports"),u(a)("products_sold_summary","Products Sold Summary")]},{actions:r(()=>[n(I,{wrap:""},{default:r(()=>[n(kt),n(c,{value:H.value,"onUpdate:value":t[0]||(t[0]=l=>H.value=l),style:{width:"100px"},options:[{value:58,label:"58 mm"},{value:80,label:"80 mm"},{value:88,label:"88 mm"}]},null,8,["value"]),n(T,{type:"primary",disabled:$.value,onClick:pt},{icon:r(()=>[n(u(Ct))]),default:r(()=>[m(" "+o(u(a)("Print_Receipt","Print Receipt")),1)]),_:1},8,["disabled"]),n(T,{loading:B.value==="excel",disabled:$.value,onClick:t[1]||(t[1]=l=>st("excel"))},{icon:r(()=>[n(u(Dt))]),default:r(()=>[m(" "+o(e.$t("Export"))+" Excel ",1)]),_:1},8,["loading","disabled"]),n(T,{loading:B.value==="pdf",disabled:$.value,onClick:t[2]||(t[2]=l=>st("pdf"))},{icon:r(()=>[n(u(Pt))]),default:r(()=>[m(" "+o(e.$t("Export"))+" PDF ",1)]),_:1},8,["loading","disabled"])]),_:1})]),_:1},8,["title","breadcrumb"]),n(h,{size:"small",style:{"margin-bottom":"16px"}},{default:r(()=>[n(I,{wrap:""},{default:r(()=>[n($t,{value:q.value,"onUpdate:value":t[3]||(t[3]=l=>q.value=l),"allow-clear":"",onChange:S},null,8,["value"]),n(c,{value:M.value,"onUpdate:value":t[4]||(t[4]=l=>M.value=l),style:{width:"190px"},options:rt.value,onChange:S},null,8,["value","options"]),n(c,{value:N.value,"onUpdate:value":t[5]||(t[5]=l=>N.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:u(a)("Till","Till"),options:at(Z.value),onChange:S},null,8,["value","placeholder","options"]),n(c,{value:G.value,"onUpdate:value":t[6]||(t[6]=l=>G.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:u(a)("Sales_Person","Sales person"),options:ut.value,onChange:S},null,8,["value","placeholder","options"]),n(c,{value:Y.value,"onUpdate:value":t[7]||(t[7]=l=>Y.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:e.$t("Categorie"),options:at(et.value),onChange:S},null,8,["value","placeholder","options"]),n(c,{value:F.value,"onUpdate:value":t[8]||(t[8]=l=>F.value=l),style:{width:"150px"},"allow-clear":"",placeholder:u(a)("Sale_Status","Sale status"),options:it.value,onChange:S},null,8,["value","placeholder","options"]),n(w,{value:A.value,"onUpdate:value":t[9]||(t[9]=l=>A.value=l),style:{width:"220px"},"allow-clear":"",placeholder:e.$t("Search"),onSearch:S},null,8,["value","placeholder"])]),_:1})]),_:1}),n(Q,{gutter:[16,16],style:{"margin-bottom":"16px"}},{default:r(()=>[(d(!0),x(E,null,W(dt.value,l=>(d(),C(O,{key:l.key,xs:12,sm:12,md:6},{default:r(()=>[n(h,{size:"small",class:"kpi-card"},{default:r(()=>[s("div",It,[s("div",{class:"kpi-icon",style:ft({background:l.tint,color:l.color})},[(d(),C(bt(l.icon)))],4),s("div",Ot,[n(P,{type:"secondary",class:"kpi-label"},{default:r(()=>[m(o(l.label),1)]),_:2},1024),s("div",Rt,[$.value?(d(),C(L,{key:0,size:"small"})):(d(),x(E,{key:1},[m(o(l.value),1)],64))])])])]),_:2},1024)]),_:2},1024))),128))]),_:1}),n(h,{size:"small"},{title:r(()=>[m(o(u(a)("Day_Report","Day Report"))+" ",1),i.value.day_status==="open"?(d(),C(R,{key:0,color:"warning",style:{"margin-left":"8px"}},{default:r(()=>[m(o(u(a)("Day_is_still_opened","Day is still opened")),1)]),_:1})):i.value.day_status==="closed"?(d(),C(R,{key:1,style:{"margin-left":"8px"}},{default:r(()=>[m(o(u(a)("Day_is_closed","Day is closed")),1)]),_:1})):K("",!0)]),extra:r(()=>[n(P,{type:"secondary",style:{"font-size":"12px"}},{default:r(()=>[m(o(u(a)("Till","Till"))+": "+o(i.value.till||e.$t("All"))+" · "+o(u(a)("Sales_Person","Sales person"))+": "+o(i.value.sales_person||e.$t("All"))+" · "+o(i.value.from)+" → "+o(i.value.to),1)]),_:1})]),default:r(()=>[n(L,{spinning:$.value},{default:r(()=>[!U.value.length&&!$.value?(d(),C(_,{key:0,description:e.$t("NodataAvailable"),style:{padding:"48px 0"}},null,8,["description"])):(d(),x("div",Et,[s("table",Nt,[s("thead",null,[s("tr",null,[s("th",Gt,o(e.$t("ProductName")),1),s("th",Yt,o(e.$t("Quantity")),1),s("th",Ft,o(u(a)("Unit_Price","Unit price")),1),s("th",At,o(e.$t("Total")),1)])]),(d(!0),x(E,null,W(U.value,l=>(d(),x("tbody",{key:l.group_id??"none"},[s("tr",Bt,[s("th",Mt,o(V(l)),1)]),(d(!0),x(E,null,W(l.items,b=>(d(),x("tr",{key:(l.group_id??0)+"-"+b.product_id+"-"+b.product_name},[s("td",null,[m(o(b.product_name)+" ",1),b.product_code?(d(),C(P,{key:0,type:"secondary",style:{display:"block","font-size":"12px"}},{default:r(()=>[m(o(b.product_code),1)]),_:2},1024)):K("",!0)]),s("td",Vt,[m(o(k(b.quantity))+" ",1),n(P,{type:"secondary",style:{"font-size":"12px"}},{default:r(()=>[m(o(b.unit),1)]),_:2},1024)]),s("td",Lt,[b.unit_price===null?(d(),x("span",Qt,"—")):(d(),x(E,{key:1},[m(o(u(D)(b.unit_price)),1)],64)),b.price_varies?(d(),C(g,{key:2,title:u(a)("Sold_at_several_prices","Sold at several prices in this period; this is the weighted average.")},{default:r(()=>[n(P,{type:"secondary",style:{"font-size":"12px"}},{default:r(()=>[...t[10]||(t[10]=[m(" ~",-1)])]),_:1})]),_:1},8,["title"])):K("",!0)]),s("td",jt,o(u(D)(b.total)),1)]))),128)),s("tr",Ht,[s("td",Jt,o(u(a)("Sub_Total","Sub Total")),1),s("td",Wt,o(k(l.sub_total_quantity)),1),t[11]||(t[11]=s("td",{class:"num"},null,-1)),s("td",Kt,o(u(D)(l.sub_total)),1)])]))),128)),s("tfoot",null,[s("tr",Xt,[s("td",Zt,o(u(a)("Grand_Total","Grand Total")),1),s("td",te,o(k(f.value.quantity)),1),t[12]||(t[12]=s("td",{class:"num"},null,-1)),s("td",ee,o(u(D)(f.value.total)),1)])])])]))]),_:1},8,["spinning"])]),_:1})])}}},me=_t(ae,[["__scopeId","data-v-ac80575d"]]);export{me as default};
