import{a as g,k as h,a5 as u,j as a,V as f,G as j,r as l,N as p,O as N}from"./index-9b_bNU-A.js";function k({label:m,badge:b,items:v,groups:r}){const{isDark:s}=g(),x=h(),{pathname:c}=u(),i=r?r.find(e=>e.items.some(n=>c.startsWith(n.path)))??r.find(e=>e.items.some(n=>c===n.path))??r[0]:null;return a.jsxs("div",{className:"space-y-0 -m-4 md:-m-6",children:[a.jsxs("div",{className:"relative overflow-hidden px-4 md:px-6 pt-5 pb-0",style:{background:s?"linear-gradient(135deg,rgba(124,58,237,0.2),rgba(91,33,182,0.1),transparent)":"linear-gradient(135deg,rgba(124,58,237,0.1),rgba(167,139,250,0.06),transparent)",borderBottom:"1px solid var(--border)"},children:[a.jsxs("div",{className:"flex items-center gap-3 mb-4",children:[a.jsxs("button",{onClick:()=>x("/app/modules"),className:"flex items-center gap-1.5 text-xs font-semibold transition-all duration-150",style:{color:"var(--text-muted)"},onMouseEnter:e=>e.currentTarget.style.color="var(--text-h)",onMouseLeave:e=>e.currentTarget.style.color="var(--text-muted)",children:[a.jsx(f,{size:12})," Modules"]}),a.jsx(j,{size:12,style:{color:"var(--text-muted)"}}),a.jsxs("div",{className:"flex items-center gap-2",children:[a.jsx("div",{className:"w-6 h-6 rounded-lg flex items-center justify-center text-xs",style:{background:"linear-gradient(135deg,#7C3AED,#5b21b6)",boxShadow:"0 3px 8px rgba(124,58,237,0.4)"},children:b}),a.jsx("span",{className:"text-xs font-bold",style:{color:"#a78bfa"},children:m})]})]}),a.jsx("style",{children:`
          .modnav-plate {
            margin-bottom: 14px; padding: 9px; border-radius: 9999px;
            background: ${s?"#242229":"#e9e4da"};
            box-shadow: ${s?"6px 6px 16px rgba(0,0,0,0.55), -6px -6px 16px rgba(255,255,255,0.035)":"6px 6px 15px rgba(183,175,160,0.55), -6px -6px 15px rgba(255,255,255,0.9)"};
          }
          .modnav-rail {
            display: flex; align-items: stretch; gap: 0;
            overflow-x: auto; padding: 5px; border-radius: 9999px;
            background: ${s?"#1f1d24":"#ece7de"};
            box-shadow: ${s?"inset 2px 2px 6px rgba(0,0,0,0.6), inset -2px -2px 6px rgba(255,255,255,0.04), inset 0 0 16px rgba(150,120,255,0.08)":"inset 2px 2px 5px rgba(183,175,160,0.6), inset -2px -2px 5px rgba(255,255,255,0.85), inset 0 0 14px rgba(255,255,255,0.55)"};
          }
          .modnav-rail::-webkit-scrollbar { display: none; }
          .modnav-tab {
            position: relative; display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9999px;
            font-size: 12px; font-weight: 600; white-space: nowrap; cursor: pointer;
            color: ${s?"var(--text-muted)":"#9a9182"}; text-decoration: none;
            transition: color .3s ease, background .35s ease, box-shadow .4s ease, transform .18s ease;
          }
          .modnav-tab:hover { color: ${s?"var(--text-h)":"#5f5849"}; }
          .modnav-tab:active { transform: scale(0.96); }
          .modnav-tab.on {
            color: ${s?"#c4b5fd":"#7C3AED"};
            background: ${s?"linear-gradient(145deg,#3a3450,#2c2840)":"#ffffff"};
            box-shadow: ${s?"0 0 16px rgba(167,139,250,0.45), 3px 3px 8px rgba(0,0,0,0.5), -2px -2px 6px rgba(255,255,255,0.05)":"0 0 16px rgba(255,255,255,0.9), 3px 3px 8px rgba(183,175,160,0.55), -3px -3px 8px rgba(255,255,255,0.95), 0 0 0 1px rgba(255,255,255,0.7)"};
          }
          .modnav-ico { opacity: .8; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; }
          .modnav-tab:hover .modnav-ico { opacity: 1; }
          .modnav-tab.on .modnav-ico { transform: scale(1.12); opacity: 1; }
          .modnav-tick {
            flex: 0 0 auto; align-self: center; width: 2px; height: 15px; margin: 0 1px;
            border-radius: 2px;
            background: ${s?"rgba(255,255,255,0.12)":"rgba(160,150,134,0.5)"};
          }
          .modnav-sub { padding: 6px; }
          .modnav-sub .modnav-tab { padding: 6px 13px; font-size: 11.5px; }
        `}),r?a.jsxs(a.Fragment,{children:[a.jsx("nav",{className:"modnav-plate",style:{marginBottom:8},children:a.jsx("div",{className:"modnav-rail",children:r.map((e,n)=>{const t=e.icon,o=e===i;return a.jsxs(l.Fragment,{children:[n>0&&a.jsx("span",{className:"modnav-tick","aria-hidden":"true"}),a.jsxs("button",{onClick:()=>x(e.items[0].path),className:`modnav-tab${o?" on":""}`,style:{border:"none",background:o?void 0:"transparent"},children:[t&&a.jsx(t,{size:13,className:"modnav-ico"}),e.label]})]},e.label)})})}),i&&i.items.length>0&&a.jsx("nav",{className:"modnav-plate modnav-sub",children:a.jsx("div",{className:"modnav-rail",children:i.items.map(({label:e,path:n,icon:t},o)=>a.jsxs(l.Fragment,{children:[o>0&&a.jsx("span",{className:"modnav-tick","aria-hidden":"true"}),a.jsxs(p,{to:n,className:({isActive:d})=>`modnav-tab${d?" on":""}`,children:[t&&a.jsx(t,{size:13,className:"modnav-ico"}),e]})]},n))})})]}):a.jsx("nav",{className:"modnav-plate",children:a.jsx("div",{className:"modnav-rail",children:v.map(({label:e,path:n,icon:t},o)=>a.jsxs(l.Fragment,{children:[o>0&&a.jsx("span",{className:"modnav-tick","aria-hidden":"true"}),a.jsxs(p,{to:n,className:({isActive:d})=>`modnav-tab${d?" on":""}`,children:[a.jsx(t,{size:13,className:"modnav-ico"}),e]})]},n))})})]}),a.jsx("div",{className:"p-4 md:p-6",children:a.jsx(N,{})})]})}export{k as M};
