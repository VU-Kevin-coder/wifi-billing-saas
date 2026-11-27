<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Hamnet | Scheduled maintenance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f5f6fa;--surface:#fff;--ink:#0b1220;--ink-2:#3b4558;--muted:#6b7588;--line:#e3e7ef;
  --brand:#4f46e5;--brand-dark:#4238d4;--brand-soft:#eceeff;--accent:#06b6d4;--accent-soft:#e0f7fb;
  --shadow:0 1px 2px rgba(11,18,32,.04),0 8px 30px rgba(11,18,32,.06);
  --shadow-lg:0 2px 4px rgba(11,18,32,.04),0 24px 50px -12px rgba(30,40,110,.2);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--ink);line-height:1.6;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;min-height:100vh;overflow-x:hidden}
h1,h2,.code{font-family:'Space Grotesk','Inter',system-ui,sans-serif;letter-spacing:-.03em;line-height:1.05}
a{color:inherit;text-decoration:none}
::selection{background:var(--brand);color:#fff}
#net{position:fixed;inset:0;z-index:0;pointer-events:none}
.top,main,footer{position:relative;z-index:1}
.wrap{width:min(1120px,100% - 40px);margin-inline:auto}

.top{padding:18px 0}
.top .wrap{display:flex;align-items:center;justify-content:space-between}
.logo{display:flex;align-items:center;gap:10px;font:700 1.35rem 'Space Grotesk',sans-serif;letter-spacing:-.03em}
.logo-mark{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:#fff;background:var(--brand)}
.back{display:inline-flex;align-items:center;gap:6px;font-size:.9rem;font-weight:500;color:var(--muted);transition:color .2s}
.back:hover{color:var(--brand)}
.back svg{transition:transform .2s}.back:hover svg{transform:translateX(-3px)}

main{flex:1;display:grid;align-items:center;padding:24px 0 48px}
.grid{display:grid;grid-template-columns:1.02fr 1fr;gap:48px;align-items:center}

.pill{display:inline-flex;align-items:center;gap:10px;padding:6px 14px 6px 10px;border-radius:999px;background:rgba(255,255,255,.92);border:1px solid var(--line);font-size:.84rem;font-weight:500;color:var(--ink-2);box-shadow:var(--shadow);margin-bottom:22px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--accent);position:relative}
.dot::after{content:'';position:absolute;inset:0;border-radius:50%;background:var(--accent);animation:ping 1.8s ease-out infinite}
@keyframes ping{to{transform:scale(3);opacity:0}}

.code{display:flex;font-size:clamp(5.5rem,17vw,10.5rem);font-weight:700;color:var(--ink);margin-bottom:10px;user-select:none}
.code span{display:inline-block;transition:transform .35s cubic-bezier(.3,1.6,.5,1),color .25s;cursor:default;animation:drop .8s cubic-bezier(.2,.8,.2,1) both}
.code span:nth-child(2){animation-delay:.08s}.code span:nth-child(3){animation-delay:.16s}
.code span:hover{transform:translateY(-14px) rotate(-4deg);color:var(--accent)}
.code span.acc{color:var(--accent)}
@keyframes drop{from{opacity:0;transform:translateY(-40px)}}

h1{font-size:clamp(1.8rem,3.4vw,2.5rem);font-weight:700;margin-bottom:14px}
.msg{color:var(--ink-2);font-size:1.08rem;max-width:480px;margin-bottom:30px}
.fade{opacity:0;transform:translateY(14px);animation:fin .7s cubic-bezier(.2,.8,.2,1) forwards;animation-delay:var(--d,.3s)}
@keyframes fin{to{opacity:1;transform:none}}

.actions{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:26px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;font:600 .95rem 'Inter',system-ui,sans-serif;padding:13px 24px;border-radius:12px;border:1px solid transparent;cursor:pointer;transition:transform .2s,box-shadow .2s,background .2s,border-color .2s}
.btn svg{flex:none}
.btn-brand{background:var(--brand);color:#fff;box-shadow:0 8px 20px rgba(79,70,229,.28)}
.btn-brand:hover{background:var(--brand-dark);box-shadow:0 14px 28px rgba(79,70,229,.36)}
.btn-ghost{background:var(--surface);border-color:var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:#c6cde0}
.btn-ink{background:var(--ink);color:#fff}
.btn-ink:hover{box-shadow:0 12px 26px rgba(11,18,32,.22)}

.meta{display:flex;flex-wrap:wrap;gap:10px}
.tag{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:10px;background:rgba(255,255,255,.92);border:1px solid var(--line);font:500 .82rem 'JetBrains Mono',ui-monospace,Menlo,monospace;color:var(--ink-2);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tag b{color:var(--muted);font-weight:500;font-family:'Inter',system-ui,sans-serif}
.links{margin-top:22px}
.links p{font-size:.78rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:10px}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{padding:8px 16px;border-radius:999px;background:rgba(255,255,255,.92);border:1px solid var(--line);font-size:.88rem;font-weight:500;color:var(--ink-2);transition:transform .2s,border-color .2s,color .2s}
.chip:hover{transform:translateY(-3px);border-color:#c9ceff;color:var(--brand)}

/* Stage */
.stage{background:rgba(255,255,255,.93);backdrop-filter:blur(8px);border:1px solid var(--line);border-radius:30px;box-shadow:var(--shadow-lg);aspect-ratio:36/28;position:relative;overflow:hidden;opacity:0;transform:scale(.96) translateY(10px);animation:stagein .9s .15s cubic-bezier(.2,.8,.2,1) forwards}
@keyframes stagein{to{opacity:1;transform:none}}
.stage svg{width:100%;height:100%;display:block}
.stage [data-depth]{transition:transform .25s ease-out}
.corner{position:absolute;top:16px;left:18px;font:500 .74rem 'JetBrains Mono',ui-monospace,monospace;color:var(--muted);display:flex;gap:8px;align-items:center}
.corner i{width:8px;height:8px;border-radius:50%;background:var(--accent);display:block;animation:blink 1.4s steps(1) infinite}
@keyframes blink{50%{opacity:.2}}

footer{padding:22px 0;color:var(--muted);font-size:.84rem}
footer .wrap{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
footer a:hover{color:var(--brand)}

/* SVG helpers */
.ox{transform-box:fill-box;transform-origin:center}
.spin{animation:spin 9s linear infinite}.spin-r{animation:spin 6s linear infinite reverse}
@keyframes spin{to{transform:rotate(360deg)}}
.arc{opacity:.2;animation:arc 2.2s ease-in-out infinite}
.arc.a2{animation-delay:.25s}.arc.a3{animation-delay:.5s}
@keyframes arc{0%,100%{opacity:.15}50%{opacity:1}}
.flick{animation:flick 1.3s steps(1) infinite}
@keyframes flick{0%{opacity:1}12%{opacity:.1}20%{opacity:1}55%{opacity:.2}62%{opacity:1}80%{opacity:.1}}
.dash{stroke-dasharray:7 9;animation:dash 1.1s linear infinite}
@keyframes dash{to{stroke-dashoffset:-32}}
.float{animation:float 4.5s ease-in-out infinite}
@keyframes float{50%{transform:translateY(-9px)}}
.wob{animation:wob 3.2s ease-in-out infinite}
@keyframes wob{0%,100%{transform:rotate(-7deg)}50%{transform:rotate(7deg)}}

@media(max-width:900px){
  .grid{grid-template-columns:1fr;gap:30px}
  .stage{order:-1;max-width:520px;width:100%;margin-inline:auto}
  .code{justify-content:flex-start}
}
@media(max-width:480px){.btn{width:100%}.actions{flex-direction:column}}
@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{animation:none!important;transition:none!important}
  .fade,.stage{opacity:1;transform:none}
  .code span{opacity:1;transform:none}
}
.bar{animation:bar 1.8s ease-in-out infinite}@keyframes bar{0%{transform:translateX(0)}50%{transform:translateX(140px)}100%{transform:translateX(0)}}
</style>
</head>
<body>
<canvas id="net" aria-hidden="true"></canvas>

<div class="top"><div class="wrap">
  <a class="logo" href="{{ route('landing') }}"><span class="logo-mark"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 010 8.4M7.8 16.2a6 6 0 010-8.4"/><path d="M19 5a10 10 0 010 14M5 19A10 10 0 015 5"/></svg></span>Hamnet</a>
  <a class="back" href="{{ route('landing') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg> Back to home</a>
</div></div>

<main><div class="wrap grid">
  <div>
    <div class="pill fade" style="--d:.1s"><span class="dot"></span>Error 503 · Maintenance</div>
    <div class="code" aria-label="503"><span class="">5</span><span class="">0</span><span class="acc">3</span></div>
    <h1 class="fade" style="--d:.45s">We are upgrading the platform.</h1>
    <p class="msg fade" style="--d:.55s">Hamnet is briefly offline while we install improvements. We will be back online shortly. Thank you for your patience.</p>
    <div class="actions fade" style="--d:.65s"><a class="btn btn-brand" href="#" data-reload><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 10-2.3 5.7"/><path d="M20 4v7h-7"/></svg>Check Again</a><a class="btn btn-ghost" href="{{ route('landing') }}#how"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>Back to Home</a></div>
    <div class="meta fade" style="--d:.75s"><span class="tag"><b>Auto-retry</b> <span><span id="count">30</span>s</span></span></div>
    
  </div>
  <div class="stage" role="img" aria-label="Scheduled maintenance illustration">
    <div class="corner"><i></i>upgrading</div>
    <svg viewBox="0 0 360 280" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dots" width="22" height="22" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.4" fill="#d5daf0"/></pattern></defs><rect width="360" height="280" fill="url(#dots)"/>
<g data-depth="10">
  <g class="ox spin" style="transform-origin:150px 112px;transform-box:view-box"><path d="M204.0 112.0 L215.2 122.3 L212.8 132.4 L198.1 136.5 L193.7 143.7 L196.7 158.7 L188.8 165.4 L174.5 160.1 L166.7 163.4 L160.3 177.2 L150.0 178.0 L141.6 165.3 L133.3 163.4 L120.0 170.8 L111.2 165.4 L111.8 150.2 L106.3 143.7 L91.2 142.0 L87.2 132.4 L96.7 120.4 L96.0 112.0 L84.8 101.7 L87.2 91.6 L101.9 87.5 L106.3 80.3 L103.3 65.3 L111.2 58.6 L125.5 63.9 L133.3 60.6 L139.7 46.8 L150.0 46.0 L158.4 58.7 L166.7 60.6 L180.0 53.2 L188.8 58.6 L188.2 73.8 L193.7 80.3 L208.8 82.0 L212.8 91.6 L203.3 103.6 Z" fill="#1a2138" stroke="#1a2138" stroke-width="4" stroke-linejoin="round"/><circle cx="150" cy="112" r="22" fill="#fff"/><circle cx="150" cy="112" r="9.9" fill="var(--accent)"/></g>
  <g class="ox spin-r" style="transform-origin:238px 172px;transform-box:view-box"><path d="M273.0 172.0 L281.2 180.6 L278.7 188.8 L267.1 191.4 L262.7 196.7 L262.4 208.6 L254.8 212.7 L244.8 206.3 L238.0 207.0 L229.4 215.2 L221.2 212.7 L218.6 201.1 L213.3 196.7 L201.4 196.4 L197.3 188.8 L203.7 178.8 L203.0 172.0 L194.8 163.4 L197.3 155.2 L208.9 152.6 L213.3 147.3 L213.6 135.4 L221.2 131.3 L231.2 137.7 L238.0 137.0 L246.6 128.8 L254.8 131.3 L257.4 142.9 L262.7 147.3 L274.6 147.6 L278.7 155.2 L272.3 165.2 Z" fill="#1a2138" stroke="#1a2138" stroke-width="4" stroke-linejoin="round"/><circle cx="238" cy="172" r="15" fill="#fff"/><circle cx="238" cy="172" r="6.8" fill="var(--accent)"/></g>
</g>
<g data-depth="18">
  <rect x="70" y="238" width="220" height="10" rx="5" fill="#e3e7ef"/>
  <rect class="bar" x="70" y="238" width="80" height="10" rx="5" fill="var(--accent)"/>
  <circle cx="64" cy="64" r="5" fill="#8c94ea"/><circle cx="306" cy="66" r="4" fill="#06b6d4"/><circle cx="46" cy="196" r="4" fill="#06b6d4"/>
</g></svg>
  </div>
</div></main>

<footer><div class="wrap"><span>&copy; {{ date('Y') }} Hamnet Systems. All rights reserved.</span><span><a href="{{ route('landing') }}">Home</a> &nbsp;·&nbsp; <a href="{{ route('landing') }}#features">Platform</a> &nbsp;·&nbsp; <a href="{{ route('landing') }}#pricing">Pricing</a></span></div></footer>

<script>
(()=>{
  const reduce=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const c=document.getElementById('net'),x=c.getContext('2d');
  const accent=getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()||'#4f46e5';
  let W,H,nodes=[],m={x:-999,y:-999},rip=[];
  function size(){
    const d=Math.min(devicePixelRatio||1,2);W=innerWidth;H=innerHeight;
    c.width=W*d;c.height=H*d;c.style.width=W+'px';c.style.height=H+'px';x.setTransform(d,0,0,d,0,0);
    const n=Math.min(90,Math.round(W*H/20000));
    nodes=Array.from({length:n},()=>({x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.28,vy:(Math.random()-.5)*.28,r:1.6+Math.random()*1.8,hot:Math.random()<.12}));
  }
  function frame(){
    x.clearRect(0,0,W,H);
    for(const a of nodes){
      if(!reduce){
        a.x+=a.vx;a.y+=a.vy;if(a.x<0||a.x>W)a.vx*=-1;if(a.y<0||a.y>H)a.vy*=-1;
        const dx=a.x-m.x,dy=a.y-m.y,d=Math.hypot(dx,dy);
        if(d<130&&d>0){a.x+=dx/d*(130-d)*.025;a.y+=dy/d*(130-d)*.025;}
      }
    }
    x.lineWidth=1;
    for(let i=0;i<nodes.length;i++)for(let j=i+1;j<nodes.length;j++){
      const a=nodes[i],b=nodes[j],d=Math.hypot(a.x-b.x,a.y-b.y);
      if(d<135){x.strokeStyle='rgba(154,163,232,'+(.5*(1-d/135)).toFixed(3)+')';x.beginPath();x.moveTo(a.x,a.y);x.lineTo(b.x,b.y);x.stroke();}
    }
    x.strokeStyle=accent;
    for(const a of nodes){
      const d=Math.hypot(a.x-m.x,a.y-m.y);
      if(d<170){x.globalAlpha=.75*(1-d/170);x.beginPath();x.moveTo(a.x,a.y);x.lineTo(m.x,m.y);x.stroke();x.globalAlpha=1;}
    }
    for(const a of nodes){x.fillStyle=a.hot?'#06b6d4':'#8c94ea';x.beginPath();x.arc(a.x,a.y,a.r*(Math.hypot(a.x-m.x,a.y-m.y)<110?1.8:1),0,6.283);x.fill();}
    for(let i=rip.length-1;i>=0;i--){
      const r=rip[i];r.t+=.016;if(r.t>1.4){rip.splice(i,1);continue;}
      x.globalAlpha=Math.max(0,1-r.t/1.4)*.6;x.strokeStyle=accent;x.lineWidth=2;x.beginPath();x.arc(r.x,r.y,r.t*170,0,6.283);x.stroke();x.globalAlpha=1;
    }
    if(!reduce)requestAnimationFrame(frame);
  }
  size();addEventListener('resize',size);frame();
  if(!reduce){
    addEventListener('pointermove',e=>{m.x=e.clientX;m.y=e.clientY;
      const nx=e.clientX/innerWidth-.5,ny=e.clientY/innerHeight-.5;
      document.querySelectorAll('[data-depth]').forEach(g=>{const d=+g.dataset.depth;g.style.transform='translate('+(nx*d).toFixed(2)+'px,'+(ny*d).toFixed(2)+'px)';});
    },{passive:true});
    document.addEventListener('mouseleave',()=>{m.x=m.y=-999;});
    addEventListener('click',e=>{rip.push({x:e.clientX,y:e.clientY,t:0});});
  }
})();
(()=>{let s=30;const el=document.getElementById('count');
const b=document.querySelector('[data-reload]');if(b)b.addEventListener('click',e=>{e.preventDefault();location.reload();});
setInterval(()=>{s--;if(s<=0){location.reload();return;}el.textContent=s;},1000);})();
</script>
</body>
</html>
