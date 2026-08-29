import{j as t,ao as d,X as p}from"./index-CgMu9dVw.js";const l={display:"block",fontSize:11,fontWeight:700,color:"var(--text-muted)",marginBottom:6,textTransform:"uppercase",letterSpacing:"0.05em"},s={width:"100%",padding:"9px 12px",background:"var(--bg-input)",border:"1px solid var(--border)",borderRadius:8,color:"var(--text-h)",fontSize:13,outline:"none",boxSizing:"border-box"},b=`
  .pr-glass {
    position: relative; border-radius: 20px; overflow: hidden;
    background: var(--bg-card); border: 1px solid var(--border);
    box-shadow: 0 1px 2px rgba(15,10,40,.06), 0 10px 30px -12px rgba(88,40,180,.28), inset 0 1px 0 var(--card-shine);
    transition: transform .32s cubic-bezier(.34,1.56,.64,1), box-shadow .32s ease, border-color .32s ease;
  }
  html:not(.light) .pr-glass { background: linear-gradient(160deg, rgba(40,34,60,.66), rgba(28,24,42,.52)); backdrop-filter: blur(14px) saturate(1.2); }
  html.light .pr-glass { background: linear-gradient(160deg, rgba(255,255,255,.9), rgba(248,245,255,.72)); backdrop-filter: blur(12px) saturate(1.1); }
  .pr-glass::after {
    content: ''; position: absolute; inset: 0; pointer-events: none; opacity: 0;
    background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,.16) 46%, transparent 60%);
    transform: translateX(-60%); transition: opacity .5s ease, transform .8s ease;
  }
  .pr-lift:hover { transform: translateY(-4px); box-shadow: 0 4px 8px rgba(15,10,40,.08), 0 26px 50px -18px rgba(108,56,210,.5), inset 0 1px 0 var(--card-shine-hover); border-color: var(--border-purple); }
  .pr-lift:hover::after { opacity: 1; transform: translateX(60%); }
  .pr-node { position: relative; transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s ease, opacity .2s ease, background .2s ease; }
  .pr-node:hover { transform: translateY(-2px) scale(1.015); }
  .pr-node:active { transform: scale(.98); }
  .pr-flow { background-size: 200% 100%; animation: mrFlow 2.2s linear infinite; }
  .pr-flow-dim { opacity: .28; animation: none; }
  .pr-kpi { position: relative; border-radius: 16px; padding: 15px 14px; cursor: pointer; overflow: hidden;
    background: var(--bg-card); border: 1px solid var(--border);
    box-shadow: 0 8px 22px -14px rgba(88,40,180,.5), inset 0 1px 0 var(--card-shine);
    transition: transform .28s cubic-bezier(.34,1.56,.64,1), box-shadow .28s ease; }
  .pr-kpi:hover { transform: translateY(-3px); box-shadow: 0 18px 36px -16px rgba(108,56,210,.55), inset 0 1px 0 var(--card-shine-hover); }
  .pr-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:40%; background:linear-gradient(180deg,rgba(255,255,255,.06),transparent); pointer-events:none; }
  .pr-li-row { transition: background .18s ease; }
  .pr-li-row:hover { background: var(--bg-input); }
  @keyframes prPop { 0%{transform:scale(.96);opacity:0} 100%{transform:scale(1);opacity:1} }
  .pr-pop { animation: prPop .22s cubic-bezier(.34,1.56,.64,1); }
  /* Receipt progress bar */
  .pr-bar { height: 7px; border-radius: 99px; background: var(--bg-input); overflow: hidden; }
  .pr-bar > span { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg,#7C3AED,#10b981); transition: width .5s cubic-bezier(.34,1.56,.64,1); }
`;function x({onClose:r,width:o=480,children:e,closeOnBackdrop:a=!1,showClose:i=!0}){return d.createPortal(t.jsx("div",{style:{position:"fixed",inset:0,background:"rgba(0,0,0,0.72)",zIndex:1e3,display:"flex",alignItems:"center",justifyContent:"center",padding:20},onClick:a?n=>n.target===n.currentTarget&&r():void 0,children:t.jsxs("div",{className:"pr-glass pr-pop",style:{width:"100%",maxWidth:o,maxHeight:"90vh",overflowY:"auto",padding:28},children:[i&&r&&t.jsx("div",{style:{position:"sticky",top:-6,height:0,zIndex:3,display:"flex",justifyContent:"flex-end",pointerEvents:"none"},children:t.jsx("button",{type:"button",onClick:r,"aria-label":"Close",title:"Close",style:{pointerEvents:"auto",marginTop:-10,marginRight:-10,width:30,height:30,display:"flex",alignItems:"center",justifyContent:"center",borderRadius:8,border:"1px solid var(--border)",background:"var(--bg-card)",color:"var(--text-muted)",cursor:"pointer"},children:t.jsx(p,{size:16})})}),e]})}),document.body)}function g({onClose:r,onConfirm:o,loading:e,disabled:a,confirmLabel:i,color:n="#7C3AED"}){return t.jsxs("div",{style:{display:"flex",gap:10,marginTop:22,justifyContent:"flex-end"},children:[t.jsx("button",{onClick:r,disabled:e,style:{padding:"9px 20px",borderRadius:9,border:"1px solid var(--border)",background:"var(--bg-card)",color:"var(--text-muted)",cursor:"pointer",fontSize:13},children:"Cancel"}),t.jsx("button",{onClick:o,disabled:e||a,style:{padding:"9px 24px",borderRadius:9,background:e||a?"rgba(124,58,237,0.4)":`linear-gradient(135deg,${n},${n}cc)`,color:"#fff",fontWeight:700,border:"none",cursor:e||a?"not-allowed":"pointer",fontSize:13,opacity:a?.6:1},children:e?"Processing…":i})]})}const u=({tone:r,children:o})=>t.jsx("p",{style:{color:"var(--text-muted)",fontSize:12,padding:"10px 14px",marginBottom:14,background:r==="danger"?"rgba(239,68,68,0.08)":"rgba(124,58,237,0.08)",borderRadius:8,border:`1px solid ${r==="danger"?"rgba(239,68,68,0.2)":"rgba(124,58,237,0.2)"}`},children:o}),h=({label:r,children:o,full:e})=>t.jsxs("div",{style:e?{gridColumn:"1/-1"}:void 0,children:[t.jsx("label",{style:l,children:r}),o]}),f=r=>t.jsx("input",{...r,style:{...s,...r.style||{}}}),v=({options:r,pairs:o,...e})=>t.jsx("select",{...e,style:{...s,cursor:"pointer"},children:r.map(a=>o?t.jsx("option",{value:a[0],children:a[1]},a[0]):t.jsx("option",{value:a,children:a},a))}),m=({label:r,value:o,strong:e})=>t.jsxs("div",{style:{display:"flex",justifyContent:"space-between",alignItems:"baseline"},children:[t.jsx("span",{style:{fontSize:e?13:12,fontWeight:e?800:600,color:e?"var(--text-h)":"var(--text-muted)"},children:r}),t.jsx("span",{style:{fontSize:e?17:13,fontWeight:e?900:700,color:e?"#a78bfa":"var(--text-h)"},children:o})]});function y({onClick:r,icon:o,color:e,bg:a,border:i,children:n}){return t.jsxs("button",{onClick:r,style:{display:"flex",alignItems:"center",justifyContent:"center",gap:5,padding:"6px 12px",borderRadius:8,background:a,border:`1px solid ${i?"var(--border)":e+"4d"}`,color:e,cursor:"pointer",fontSize:12,fontWeight:600,whiteSpace:"nowrap"},children:[t.jsx(o,{size:12})," ",n]})}function k({cfg:r}){return t.jsx("span",{style:{display:"inline-flex",alignItems:"center",gap:4,padding:"3px 10px",borderRadius:20,fontSize:11,fontWeight:700,background:r.bg,color:r.color,border:`1px solid ${r.color}40`,whiteSpace:"nowrap"},children:r.label})}export{y as A,h as F,u as I,b as K,g as M,x as O,v as S,f as T,m as a,k as b,s as i,l};
