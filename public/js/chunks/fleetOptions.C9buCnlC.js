import{aP as t,aZ as n,aU as o,aV as u,an as i}from"../app.DLipW_fg.js";/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const v=t("bike",[["circle",{cx:"18.5",cy:"17.5",r:"3.5",key:"15x4ox"}],["circle",{cx:"5.5",cy:"17.5",r:"3.5",key:"1noe27"}],["circle",{cx:"15",cy:"5",r:"1",key:"19l28e"}],["path",{d:"M12 17.5V14l-3-3 4-3 2 3h2",key:"1npguv"}]]);/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const s=t("bus",[["path",{d:"M8 6v6",key:"18i7km"}],["path",{d:"M15 6v6",key:"1sg6z9"}],["path",{d:"M2 12h19.6",key:"de5uta"}],["path",{d:"M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3",key:"1wwztk"}],["circle",{cx:"7",cy:"18",r:"2",key:"19iecd"}],["path",{d:"M9 18h5",key:"lrx6i"}],["circle",{cx:"16",cy:"18",r:"2",key:"1v4tcr"}]]);/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const _=t("caravan",[["path",{d:"M18 19V9a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v8a2 2 0 0 0 2 2h2",key:"19jm3t"}],["path",{d:"M2 9h3a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2",key:"13hakp"}],["path",{d:"M22 17v1a1 1 0 0 1-1 1H10v-9a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v9",key:"1crci8"}],["circle",{cx:"8",cy:"19",r:"2",key:"t8fc5s"}]]);/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const k=t("container",[["path",{d:"M22 7.7c0-.6-.4-1.2-.8-1.5l-6.3-3.9a1.72 1.72 0 0 0-1.7 0l-10.3 6c-.5.2-.9.8-.9 1.4v6.6c0 .5.4 1.2.8 1.5l6.3 3.9a1.72 1.72 0 0 0 1.7 0l10.3-6c.5-.3.9-1 .9-1.5Z",key:"1t2lqe"}],["path",{d:"M10 21.9V14L2.1 9.1",key:"o7czzq"}],["path",{d:"m10 14 11.9-6.9",key:"zm5e20"}],["path",{d:"M14 19.8v-8.1",key:"159ecu"}],["path",{d:"M18 17.5V9.4",key:"11uown"}]]);/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const g=t("forklift",[["path",{d:"M12 12H5a2 2 0 0 0-2 2v5",key:"7zsz91"}],["path",{d:"M15 19h7",key:"1askl3"}],["path",{d:"M16 19V2",key:"1gf9nk"}],["path",{d:"M6 12V7a2 2 0 0 1 2-2h2.172a2 2 0 0 1 1.414.586l3.828 3.828A2 2 0 0 1 16 10.828",key:"enx9tf"}],["path",{d:"M7 19h4",key:"fumhkk"}],["circle",{cx:"13",cy:"19",r:"2",key:"wjnkru"}],["circle",{cx:"5",cy:"19",r:"2",key:"v8kfzx"}]]),e=(l,r)=>i.global.t(l,r||{}),a=[{value:"car",get label(){return e("Fl_Car")},icon:n},{value:"van",get label(){return e("Fl_Van")},icon:_},{value:"truck",get label(){return e("Fl_Truck")},icon:o},{value:"bus",get label(){return e("Fl_Bus")},icon:s},{value:"motorcycle",get label(){return e("Fl_Motorcycle")},icon:v},{value:"forklift",get label(){return e("Fl_Forklift")},icon:g},{value:"trailer",get label(){return e("Fl_Trailer")},icon:k},{value:"other",get label(){return e("Fl_Other")},icon:u}];function h(l){return(a.find(r=>r.value===l)||a[0]).icon}const p=[{value:"active",get label(){return e("Fl_Active")},color:"success"},{value:"maintenance",get label(){return e("Fl_In_Maintenance")},color:"warning"},{value:"inactive",get label(){return e("Fl_Inactive")},color:"default"},{value:"sold",get label(){return e("Fl_Sold")},color:"error"}],y=[{value:"petrol",get label(){return e("Fl_Petrol")}},{value:"diesel",get label(){return e("Fl_Diesel")}},{value:"electric",get label(){return e("Fl_Electric")}},{value:"hybrid",get label(){return e("Fl_Hybrid")}},{value:"lpg",get label(){return e("Fl_LPG")}},{value:"cng",get label(){return e("Fl_CNG")}}],F=[{value:"service",get label(){return e("Fl_Service")},color:"blue"},{value:"repair",get label(){return e("Fl_Repair")},color:"volcano"},{value:"tyres",get label(){return e("Fl_Tyres")},color:"purple"},{value:"inspection",get label(){return e("Fl_Inspection")},color:"cyan"},{value:"insurance",get label(){return e("Fl_Insurance")},color:"geekblue"},{value:"other",get label(){return e("Fl_Other")},color:"default"}],f=[{value:"scheduled",get label(){return e("Fl_Scheduled")},color:"processing"},{value:"in_progress",get label(){return e("Fl_In_Progress")},color:"warning"},{value:"completed",get label(){return e("Fl_Completed")},color:"success"}],M=[{value:"active",get label(){return e("Fl_Out")},color:"processing"},{value:"completed",get label(){return e("Fl_Returned")},color:"success"},{value:"cancelled",get label(){return e("Fl_Cancelled")},color:"default"}];function b(l,r){return l.find(c=>c.value===r)||{value:r,label:r||"—",color:"default"}}function x(l,r){return b(l,r).label}function T(l,r=30){return l==null?null:l<0?{color:"error",text:e("Fl_Overdue_By_N_D",{n:Math.abs(l)}),level:"overdue"}:l===0?{color:"error",text:e("Fl_Due_Today"),level:"overdue"}:l<=r?{color:"warning",text:e("Fl_N_D_Left",{n:l}),level:"soon"}:null}export{M as A,y as F,F as M,a as V,p as a,f as b,T as e,x as l,b as o,h as t};
