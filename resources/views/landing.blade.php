<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hamnet | The OS for Wi-Fi Operators</title>
<meta name="description" content="Automate your MikroTik fleet, accept Mobile Money and manage your whole Wi-Fi business from one secure cloud dashboard.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<script type="importmap">
{"imports":{
  "three":"https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
  "three/addons/":"https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
}}
</script>
<style>
:root{
  --bg:#f5f6fa; --surface:#fff; --ink:#0b1220; --ink-2:#3b4558; --muted:#6b7588;
  --line:#e3e7ef; --brand:#4f46e5; --brand-dark:#4238d4; --brand-soft:#eceeff; --cyan:#06b6d4;
  --green:#10b981; --radius:20px;
  --shadow:0 1px 2px rgba(11,18,32,.04),0 8px 30px rgba(11,18,32,.06);
  --shadow-lg:0 2px 4px rgba(11,18,32,.04),0 24px 50px -12px rgba(30,40,110,.2);
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.6;overflow-x:hidden;-webkit-font-smoothing:antialiased}
h1,h2,h3,h4{font-family:'Space Grotesk',sans-serif;letter-spacing:-.025em;line-height:1.1}
a{color:inherit;text-decoration:none}
::selection{background:var(--brand);color:#fff}
.container{width:min(1160px,100% - 40px);margin-inline:auto;position:relative}
header,section,footer{position:relative;z-index:1}

/* 3D canvas, progress, cursor */
#bg{position:fixed;inset:0;width:100vw;height:100vh;z-index:0;pointer-events:none;display:block}
#bar{position:fixed;top:0;left:0;height:3px;width:100%;background:var(--brand);transform:scaleX(0);transform-origin:left;z-index:60}
#cur{position:fixed;left:0;top:0;width:34px;height:34px;margin:-17px 0 0 -17px;border:1.5px solid var(--brand);border-radius:50%;pointer-events:none;z-index:100;opacity:0;transition:opacity .3s,width .25s,height .25s,margin .25s,background .25s;will-change:transform}
#cur.on{opacity:.7}
#cur.big{width:60px;height:60px;margin:-30px 0 0 -30px;background:rgba(79,70,229,.08)}
@media(hover:none),(pointer:coarse){#cur{display:none}}
.anchor{position:relative;pointer-events:none}
.hint{position:absolute;left:50%;bottom:-6px;transform:translateX(-50%);font-size:.75rem;font-weight:500;color:var(--muted);display:flex;gap:6px;align-items:center;white-space:nowrap;background:rgba(255,255,255,.88);border:1px solid var(--line);padding:4px 12px;border-radius:999px}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font:600 .95rem 'Inter',sans-serif;padding:13px 24px;border-radius:12px;border:1px solid transparent;cursor:pointer;transition:transform .2s,box-shadow .2s,background .2s,border-color .2s}
.btn-primary{background:var(--ink);color:#fff}
.btn-primary:hover{box-shadow:0 12px 26px rgba(11,18,32,.22)}
.btn-brand{background:var(--brand);color:#fff;box-shadow:0 8px 20px rgba(79,70,229,.28)}
.btn-brand:hover{background:var(--brand-dark);box-shadow:0 14px 28px rgba(79,70,229,.36)}
.btn-ghost{background:var(--surface);border-color:var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:#c6cde0}
.btn-sm{padding:9px 18px;font-size:.88rem;border-radius:10px}
.btn-block{width:100%}
.btn i{transition:transform .25s}
.btn:hover i.bi-arrow-right{transform:translateX(4px)}

/* Nav */
.nav{position:fixed;inset:0 0 auto 0;z-index:50;padding:14px 0;transition:background .3s,box-shadow .3s}
.nav.scrolled,.nav.open-menu{background:rgba(245,246,250,.86);backdrop-filter:blur(14px);box-shadow:0 1px 0 var(--line)}
.nav-inner{display:flex;align-items:center;justify-content:space-between}
.logo{display:flex;align-items:center;gap:10px;font:700 1.35rem 'Space Grotesk',sans-serif;letter-spacing:-.03em}
.logo-mark{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:#fff;font-size:1.05rem;background:var(--brand)}
.nav-links{display:flex;align-items:center;gap:30px}
.nav-links a.link{color:var(--ink-2);font-weight:500;font-size:.95rem;transition:color .2s;position:relative}
.nav-links a.link::after{content:'';position:absolute;left:0;right:0;bottom:-4px;height:2px;background:var(--brand);transform:scaleX(0);transform-origin:left;transition:transform .25s}
.nav-links a.link:hover{color:var(--brand)}
.nav-links a.link:hover::after{transform:scaleX(1)}
.nav-cta{display:flex;align-items:center;gap:10px}
.menu-btn{display:none;background:none;border:0;font-size:1.8rem;color:var(--ink);cursor:pointer}

/* Hero */
.hero{padding:140px 0 70px}
.hero-grid{display:grid;grid-template-columns:1fr 1.05fr;gap:40px;align-items:center}
.pill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px 6px 8px;border-radius:999px;background:rgba(255,255,255,.9);border:1px solid var(--line);font-size:.84rem;font-weight:500;color:var(--ink-2);box-shadow:var(--shadow);margin-bottom:26px}
.pill b{background:var(--brand-soft);color:var(--brand);padding:3px 10px;border-radius:999px;font-weight:600;font-size:.78rem}
.hero h1{font-size:clamp(2.6rem,5.2vw,4.1rem);font-weight:700;margin-bottom:22px}
.w{display:inline-block;overflow:hidden;vertical-align:top;padding-bottom:.1em;margin-bottom:-.1em}
.w>span{display:inline-block;transform:translateY(112%);animation:rise .9s cubic-bezier(.2,.8,.2,1) forwards;animation-delay:calc(var(--i)*70ms + 150ms)}
@keyframes rise{to{transform:none}}
.grad{color:var(--brand)}
.fade-in{opacity:0;transform:translateY(16px);animation:fin .8s cubic-bezier(.2,.8,.2,1) forwards;animation-delay:var(--d,.9s)}
@keyframes fin{to{opacity:1;transform:none}}
.hero p.lead{font-size:1.15rem;color:var(--ink-2);max-width:500px;margin-bottom:34px}
.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:30px}
.hero-badges{display:flex;gap:22px;flex-wrap:wrap;color:var(--muted);font-size:.88rem;font-weight:500}
.hero-badges span{display:flex;align-items:center;gap:8px}
.hero-badges i{color:var(--green);font-size:1.1rem}

.visual{position:relative;padding-bottom:36px}
.hero-anchor{height:370px;margin-bottom:6px}
.terminal{background:#0e1424;border-radius:16px;box-shadow:var(--shadow-lg);overflow:hidden;border:1px solid #1d2540;position:relative}
.t-head{display:flex;align-items:center;gap:7px;padding:13px 16px;background:#141b30;border-bottom:1px solid #1d2540}
.t-head i{width:11px;height:11px;border-radius:50%;display:block}
.t-head span{margin-left:10px;font-size:.75rem;color:#7d88a6}
.t-body{padding:18px 22px;font:400 .78rem/1.8 'JetBrains Mono',monospace;color:#b6c2e2;overflow-x:auto;white-space:nowrap}
.t-body .p{color:#5e6b8f}.t-body .c{color:#e6ebff}.t-body .ok{color:#34d399}.t-body .k{color:#8ab4ff}
.cursor{display:inline-block;width:8px;height:14px;background:#34d399;vertical-align:middle;animation:blink 1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.float-card{position:absolute;background:var(--surface);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow-lg);padding:16px 18px;z-index:2}
.fc-rev{right:-12px;bottom:0;width:230px;animation:bob 6s ease-in-out infinite}
.fc-pay{left:-26px;bottom:60px;display:flex;align-items:center;gap:12px;animation:bob 7s ease-in-out infinite reverse}
@keyframes bob{50%{transform:translateY(-8px)}}
.fc-label{font-size:.74rem;color:var(--muted);font-weight:500}
.fc-value{font:700 1.5rem 'Space Grotesk',sans-serif;letter-spacing:-.02em}
.fc-delta{font-size:.76rem;color:var(--green);font-weight:600}
.spark{display:flex;align-items:flex-end;gap:5px;height:42px;margin-top:10px}
.spark i{flex:1;border-radius:4px 4px 2px 2px;background:var(--brand);transform-origin:bottom;animation:grow .9s cubic-bezier(.2,.8,.2,1) both}
.spark i:nth-child(2){animation-delay:.08s}.spark i:nth-child(3){animation-delay:.16s}.spark i:nth-child(4){animation-delay:.24s}.spark i:nth-child(5){animation-delay:.32s}.spark i:nth-child(6){animation-delay:.4s}.spark i:nth-child(7){animation-delay:.48s}
@keyframes grow{from{transform:scaleY(0)}}
.pay-ic{width:38px;height:38px;border-radius:11px;background:#e7f9f1;color:var(--green);display:grid;place-items:center;font-size:1.2rem}
.pay-t{font-size:.84rem;font-weight:600;line-height:1.3}.pay-s{font-size:.74rem;color:var(--muted)}

/* Marquee */
.trust{padding:26px 0 70px}
.trust p{text-align:center;font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:22px}
.marquee{overflow:hidden;padding:6px 0}
.track{display:flex;gap:14px;width:max-content;animation:scroll 28s linear infinite}
.marquee:hover .track{animation-play-state:paused}
@keyframes scroll{to{transform:translateX(calc(-50% - 7px))}}
.chip{display:flex;align-items:center;gap:10px;padding:12px 24px;background:rgba(255,255,255,.92);border:1px solid var(--line);border-radius:14px;font:600 1rem 'Space Grotesk',sans-serif;color:var(--ink-2);box-shadow:var(--shadow);transition:transform .25s,border-color .25s;white-space:nowrap}
.chip:hover{transform:translateY(-3px);border-color:#c9ceff}
.chip i{color:var(--brand)}

.eyebrow{display:inline-block;font-size:.8rem;font-weight:600;color:var(--brand);letter-spacing:.1em;text-transform:uppercase;margin-bottom:14px}
.sec-head{max-width:640px;margin-bottom:50px}
.sec-head h2{font-size:clamp(2rem,4vw,3rem);font-weight:700;margin-bottom:16px}
.sec-head p{color:var(--ink-2);font-size:1.1rem}

/* Features */
.features{padding:90px 0}
.features .anchor.abs{position:absolute;right:0;top:-30px;width:min(400px,36%);height:290px}
.bento{display:grid;grid-template-columns:repeat(6,1fr);gap:18px}
.card{background:rgba(255,255,255,.93);backdrop-filter:blur(10px);border:1px solid var(--line);border-radius:var(--radius);padding:30px;box-shadow:var(--shadow);transition:transform .3s,box-shadow .3s,border-color .3s;position:relative;overflow:hidden;will-change:transform}
.card:hover{border-color:#c9ceff;box-shadow:var(--shadow-lg)}
.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}
.ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;font-size:1.3rem;margin-bottom:22px;background:var(--brand-soft);color:var(--brand);transition:transform .4s cubic-bezier(.3,1.6,.5,1),background .3s,color .3s}
.card:hover .ic{transform:rotate(-8deg) scale(1.1);background:var(--brand);color:#fff}
.card h3{font-size:1.25rem;font-weight:600;margin-bottom:10px}
.card p{color:var(--ink-2);font-size:.96rem}
.card.accent{background:var(--brand);color:#fff;border-color:var(--brand)}
.card.accent p{color:rgba(255,255,255,.88)}
.card.accent .ic{background:rgba(255,255,255,.18);color:#fff}
.card.accent:hover .ic{background:#fff;color:var(--brand)}
.mini-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}
.mini-list span{font-size:.78rem;font-weight:600;padding:6px 12px;border-radius:999px;background:var(--bg);border:1px solid var(--line);color:var(--ink-2)}
.card.accent .mini-list span{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.28);color:#fff}

/* How it works */
.how{padding:90px 0;border-block:1px solid var(--line)}
.how-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:50px;align-items:start}
.how .sec-head{margin-bottom:34px}
.steps{display:grid;gap:16px}
.step{display:flex;gap:18px;padding:22px;background:rgba(255,255,255,.93);backdrop-filter:blur(10px);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);transition:transform .4s cubic-bezier(.2,.8,.2,1),border-color .3s,box-shadow .3s,opacity .5s}
.step.active{border-color:var(--brand);box-shadow:var(--shadow-lg);transform:translateX(10px)}
.num{flex:none;width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font:700 1.05rem 'Space Grotesk',sans-serif;color:var(--brand);background:var(--brand-soft);border:1px solid #d9dcff;transition:background .3s,color .3s}
.step.active .num{background:var(--brand);color:#fff}
.step h3{font-size:1.2rem;margin-bottom:6px}
.step p{color:var(--ink-2);font-size:.96rem}
.how .anchor{position:sticky;top:16vh;height:min(460px,62vh)}

/* Pricing */
.pricing{padding:100px 0}
.pricing-in{max-width:860px;margin-inline:auto;position:relative}
.pricing .sec-head{max-width:460px}
.pricing .anchor.abs{position:absolute;right:0;top:-40px;width:min(330px,40%);height:300px}
.price-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}
.plan{background:rgba(255,255,255,.95);backdrop-filter:blur(10px);border:1px solid var(--line);border-radius:24px;padding:38px;box-shadow:var(--shadow);position:relative;transition:transform .3s,box-shadow .3s;will-change:transform}
.plan:hover{box-shadow:var(--shadow-lg)}
.plan.pop{border:2px solid var(--brand);box-shadow:var(--shadow-lg)}
.badge{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--brand);color:#fff;padding:5px 16px;border-radius:999px;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
.plan h3{font-size:1.4rem;margin-bottom:6px}
.plan .desc{color:var(--muted);font-size:.95rem;margin-bottom:24px}
.amount{display:flex;align-items:baseline;gap:8px;margin-bottom:26px}
.amount b{font:700 3rem 'Space Grotesk',sans-serif;letter-spacing:-.04em}
.amount span{color:var(--muted)}
.plan ul{list-style:none;margin-top:28px;display:grid;gap:13px}
.plan li{display:flex;align-items:center;gap:12px;color:var(--ink-2);font-size:.96rem}
.plan li i{color:var(--brand);font-size:1.1rem}

/* CTA */
.cta{padding:20px 0 100px}
.cta-box{border-radius:32px;padding:76px 30px;text-align:center;color:#fff;background:#1d1a5c;position:relative;overflow:hidden}
.cta-box h2{font-size:clamp(2rem,4.2vw,3.1rem);margin-bottom:16px}
.cta-box p{color:rgba(255,255,255,.8);max-width:520px;margin:0 auto 32px;font-size:1.08rem}
.cta-box .btn-light{background:#fff;color:var(--ink)}
.cta-box .btn-light:hover{box-shadow:0 14px 30px rgba(0,0,0,.25)}
.cta-ring{position:absolute;left:50%;top:50%;width:260px;height:260px;margin:-130px 0 0 -130px;border:1.5px solid rgba(255,255,255,.28);border-radius:50%;animation:ping 5s ease-out infinite;pointer-events:none}
.cta-ring:nth-of-type(2){animation-delay:1.25s}.cta-ring:nth-of-type(3){animation-delay:2.5s}.cta-ring:nth-of-type(4){animation-delay:3.75s}
@keyframes ping{0%{transform:scale(.4);opacity:.9}100%{transform:scale(4.2);opacity:0}}
.cta-box>*:not(.cta-ring){position:relative;z-index:1}

footer{border-top:1px solid var(--line);padding:34px 0;color:var(--muted);font-size:.88rem;background:rgba(245,246,250,.92)}
.foot{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}
.foot .logo{font-size:1.15rem;color:var(--ink)}
.foot .logo-mark{width:28px;height:28px;font-size:.9rem;border-radius:8px}
.foot-links{display:flex;gap:26px}.foot-links a:hover{color:var(--brand)}

.reveal{opacity:0;transform:translateY(26px);transition:opacity .8s ease,transform .8s cubic-bezier(.2,.8,.2,1)}
.reveal.in{opacity:1;transform:none}
@media(prefers-reduced-motion:reduce){
  *,*::before{animation:none!important;transition:none!important}
  .reveal,.fade-in{opacity:1;transform:none}
  .w>span{transform:none}
}

@media(max-width:960px){
  .hero{padding-top:120px}
  .hero-grid{grid-template-columns:1fr;gap:30px}
  .hero-anchor{height:300px}
  .bento{grid-template-columns:repeat(2,1fr)}
  .span-2,.span-3,.span-4{grid-column:span 2}
  .how-grid{grid-template-columns:1fr;gap:10px}
  .how .anchor{position:relative;top:0;height:260px;order:-1}
  .features .anchor.abs,.pricing .anchor.abs{position:relative;right:auto;top:0;width:100%;height:230px;margin:-20px 0 20px}
  .fc-pay{left:-4px}.fc-rev{right:-4px}
}
@media(max-width:760px){
  .menu-btn{display:block}
  .nav-links{position:absolute;top:100%;left:0;right:0;flex-direction:column;align-items:stretch;gap:0;background:var(--surface);border-bottom:1px solid var(--line);padding:10px 20px 20px;display:none;box-shadow:0 20px 30px rgba(11,18,32,.08)}
  .nav-links.open{display:flex}
  .nav-links a.link{padding:14px 0;border-bottom:1px solid var(--line)}
  .nav-cta{flex-direction:column;margin-top:16px}.nav-cta .btn{width:100%}
  .bento{grid-template-columns:1fr}.span-2,.span-3,.span-4{grid-column:span 1}
  .price-grid{grid-template-columns:1fr}
  .fc-pay{display:none}.fc-rev{position:relative;right:auto;bottom:auto;margin:-24px 12px 0 auto}
  .visual{padding-bottom:0}
}
</style>
</head>
<body>
<canvas id="bg" aria-hidden="true"></canvas>
<div id="bar"></div>
<div id="cur"></div>

<nav class="nav" id="nav">
  <div class="container nav-inner">
    <a class="logo" href="{{ route('landing') }}"><span class="logo-mark"><i class="bi bi-broadcast"></i></span>Hamnet</a>
    <button class="menu-btn" id="menuBtn" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
    <div class="nav-links" id="navLinks">
      <a class="link" href="#features">Platform</a>
      <a class="link" href="#how">How it works</a>
      <a class="link" href="#pricing">Pricing</a>
      <div class="nav-cta">
        <a class="btn btn-ghost btn-sm" href="{{ route('login') }}">Sign In</a>
        <a class="btn btn-primary btn-sm" href="{{ route('register') }}">Start for Free</a>
      </div>
    </div>
  </div>
</nav>

<header class="hero">
  <div class="container hero-grid">
    <div>
      <div class="pill fade-in" style="--d:.1s"><b>New</b> Built for East African ISPs</div>
      <h1 aria-label="The modern operating system for your Wi-Fi business.">
        <span class="w" style="--i:0"><span>The</span></span>
        <span class="w" style="--i:1"><span>modern</span></span>
        <span class="w" style="--i:2"><span>operating</span></span>
        <span class="w" style="--i:3"><span>system</span></span>
        <span class="w" style="--i:4"><span>for</span></span>
        <span class="w" style="--i:5"><span>your</span></span>
        <span class="w" style="--i:6"><span class="grad">Wi-Fi</span></span>
        <span class="w" style="--i:7"><span class="grad">business.</span></span>
      </h1>
      <p class="lead fade-in" style="--d:.85s">Stop losing money to manual vouchers and offline routers. Automate your MikroTik fleet, accept Mobile Money, and manage your entire network from one secure cloud dashboard.</p>
      <div class="hero-actions fade-in" style="--d:1s">
        <a href="{{ route('register') }}" class="btn btn-brand">Create Free Account <i class="bi bi-arrow-right"></i></a>
        <a href="#features" class="btn btn-ghost">Explore Features</a>
      </div>
      <div class="hero-badges fade-in" style="--d:1.15s">
        <span><i class="bi bi-shield-check"></i> WireGuard VPN secure</span>
        <span><i class="bi bi-phone-fill"></i> ObPay &amp; MoMo ready</span>
      </div>
    </div>

    <div class="visual">
      <div class="anchor hero-anchor" id="a-router"><div class="hint"><i class="bi bi-cursor"></i> Hover the router, click to send a signal</div></div>
      <div class="terminal">
        <div class="t-head"><i style="background:#ff5f57"></i><i style="background:#febc2e"></i><i style="background:#28c840"></i><span>MikroTik Terminal</span></div>
        <div class="t-body">
          <div><span class="p">[admin@MikroTik] &gt;</span> <span class="c">/tool fetch url=<span class="k">"https://app.hamnet.com/api/provision/bootstrap?token=xyz"</span></span></div>
          <div class="ok">status: finished</div>
          <div class="ok">downloaded: 2KiB</div>
          <div>&nbsp;</div>
          <div><span class="p">[admin@MikroTik] &gt;</span> <span class="c">Running WireGuard setup...</span></div>
          <div><span class="p">[admin@MikroTik] &gt;</span> <span class="c">Configuring API-SSL on 8729...</span></div>
          <div><span class="p">[admin@MikroTik] &gt;</span> <span class="c">Enrolling router identity...</span></div>
          <div>&nbsp;</div>
          <div class="ok">✓ Router successfully connected to Hamnet Cloud <span class="cursor"></span></div>
        </div>
      </div>
      <div class="float-card fc-pay">
        <div class="pay-ic"><i class="bi bi-check2-circle"></i></div>
        <div><div class="pay-t">Payment received</div><div class="pay-s">Voucher activated instantly</div></div>
      </div>
      <div class="float-card fc-rev">
        <div class="fc-label">Today's revenue <span style="opacity:.6">(sample)</span></div>
        <div class="fc-value">UGX 184,000</div>
        <div class="fc-delta"><i class="bi bi-arrow-up-right"></i> Live from your dashboard</div>
        <div class="spark"><i style="height:35%"></i><i style="height:55%"></i><i style="height:42%"></i><i style="height:70%"></i><i style="height:58%"></i><i style="height:88%"></i><i style="height:100%"></i></div>
      </div>
    </div>
  </div>
</header>

<section class="trust">
  <div class="container"><p>Powered by enterprise-grade technology</p></div>
  <div class="marquee" aria-label="Technology stack">
    <div class="track">
      <div class="chip"><i class="bi bi-router"></i>MikroTik</div>
      <div class="chip"><i class="bi bi-shield-lock"></i>WireGuard</div>
      <div class="chip"><i class="bi bi-key"></i>FreeRADIUS</div>
      <div class="chip"><i class="bi bi-wallet2"></i>ObPay</div>
      <div class="chip"><i class="bi bi-phone"></i>MTN Mobile Money</div>
      <div class="chip"><i class="bi bi-phone"></i>Airtel Money</div>
      <div class="chip"><i class="bi bi-router"></i>MikroTik</div>
      <div class="chip"><i class="bi bi-shield-lock"></i>WireGuard</div>
      <div class="chip"><i class="bi bi-key"></i>FreeRADIUS</div>
      <div class="chip"><i class="bi bi-wallet2"></i>ObPay</div>
      <div class="chip"><i class="bi bi-phone"></i>MTN Mobile Money</div>
      <div class="chip"><i class="bi bi-phone"></i>Airtel Money</div>
    </div>
  </div>
</section>

<section id="features" class="features">
  <div class="container">
    <div class="sec-head reveal">
      <span class="eyebrow">Platform</span>
      <h2>Run your network.<br>Not your paperwork.</h2>
      <p>We handle the complex networking and security so you can focus on selling vouchers and growing your business.</p>
    </div>
    <div class="anchor abs" id="a-rack"></div>

    <div class="bento">
      <div class="card accent span-4 reveal" data-tilt>
        <div class="ic"><i class="bi bi-router"></i></div>
        <h3>Zero-Touch Onboarding</h3>
        <p style="max-width:560px">Connect any MikroTik router to the cloud using a single copy-paste script. Our system automatically provisions the secure WireGuard VPN tunnel and configures the router for you.</p>
        <div class="mini-list"><span>One script</span><span>Auto VPN tunnel</span><span>Auto configuration</span></div>
      </div>
      <div class="card span-2 reveal" data-tilt>
        <div class="ic"><i class="bi bi-phone"></i></div>
        <h3>Instant Mobile Money</h3>
        <p>Integrated directly with ObPay for MTN and Airtel. Customers buy vouchers on their phones, and our system automatically creates their RADIUS session in real time.</p>
      </div>
      <div class="card span-3 reveal" data-tilt>
        <div class="ic"><i class="bi bi-shield-lock"></i></div>
        <h3>Secure RADIUS Auth</h3>
        <p>Stop relying on local router databases. We use FreeRADIUS over an encrypted WireGuard tunnel to authenticate users instantly, with no need for a public IP or port forwarding.</p>
      </div>
      <div class="card span-3 reveal" data-tilt>
        <div class="ic"><i class="bi bi-gear"></i></div>
        <h3>Automated Provisioning</h3>
        <p>When a customer pays, our system automatically commands the router to apply their specific speed limit and data cap via RADIUS policy. No manual intervention.</p>
      </div>
      <div class="card span-3 reveal" data-tilt>
        <div class="ic"><i class="bi bi-bar-chart-line"></i></div>
        <h3>Truthful Analytics</h3>
        <p>No more guessing. See exactly how much revenue you've made, how many devices are connected, and which packages are your most popular.</p>
      </div>
      <div class="card span-3 reveal" data-tilt>
        <div class="ic"><i class="bi bi-people"></i></div>
        <h3>Multi-Tenant SaaS</h3>
        <p>Manage multiple Wi-Fi businesses from one Platform Admin account. Each tenant gets their own isolated dashboard, data, and staff roles.</p>
      </div>
    </div>
  </div>
</section>

<section id="how" class="how">
  <div class="container how-grid">
    <div>
      <div class="sec-head reveal">
        <span class="eyebrow">How it works</span>
        <h2>From router to revenue</h2>
        <p>Three steps between a box on the wall and a paying hotspot.</p>
      </div>
      <div class="steps" id="steps">
        <div class="step reveal" data-step="0"><div class="num">1</div><div><h3>Paste one script</h3><p>Run the bootstrap command in your MikroTik terminal. Hamnet sets up the WireGuard tunnel and enrolls the router.</p></div></div>
        <div class="step reveal" data-step="1"><div class="num">2</div><div><h3>Customers pay by phone</h3><p>Buyers purchase vouchers with MTN or Airtel Mobile Money through ObPay.</p></div></div>
        <div class="step reveal" data-step="2"><div class="num">3</div><div><h3>Access is applied instantly</h3><p>RADIUS creates the session and enforces the speed limit and data cap automatically.</p></div></div>
      </div>
    </div>
    <div><div class="anchor" id="a-globe"></div></div>
  </div>
</section>

<section id="pricing" class="pricing">
  <div class="container">
    <div class="pricing-in">
      <div class="sec-head reveal">
        <span class="eyebrow">Pricing</span>
        <h2>Simple pricing</h2>
        <p>Start for free. Upgrade when you start making money.</p>
      </div>
      <div class="anchor abs" id="a-phone"></div>
      <div class="price-grid">
        <div class="plan reveal" data-tilt>
          <h3>Starter</h3>
          <p class="desc">Perfect for side hustles and small cafes.</p>
          <div class="amount"><b>Free</b><span>/ forever</span></div>
          <a href="{{ route('register') }}" class="btn btn-ghost btn-block">Get Started</a>
          <ul>
            <li><i class="bi bi-check-circle-fill"></i> 1 MikroTik Router</li>
            <li><i class="bi bi-check-circle-fill"></i> Up to 500 Vouchers/mo</li>
            <li><i class="bi bi-check-circle-fill"></i> Mobile Money Integration</li>
            <li><i class="bi bi-check-circle-fill"></i> Basic Analytics</li>
          </ul>
        </div>
        <div class="plan pop reveal" data-tilt>
          <div class="badge">Recommended</div>
          <h3>Professional</h3>
          <p class="desc">For serious Wi-Fi businesses.</p>
          <div class="amount"><b>UGX 50k</b><span>/ month</span></div>
          <a href="{{ route('register') }}" class="btn btn-brand btn-block">Start 14-Day Trial</a>
          <ul>
            <li><i class="bi bi-check-circle-fill"></i> Unlimited Routers</li>
            <li><i class="bi bi-check-circle-fill"></i> Unlimited Vouchers</li>
            <li><i class="bi bi-check-circle-fill"></i> SMS Expiry Reminders</li>
            <li><i class="bi bi-check-circle-fill"></i> Advanced Revenue Analytics</li>
            <li><i class="bi bi-check-circle-fill"></i> Priority Support</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="cta">
  <div class="container">
    <div class="cta-box reveal">
      <span class="cta-ring"></span><span class="cta-ring"></span><span class="cta-ring"></span><span class="cta-ring"></span>
      <h2>Ready to put your Wi-Fi on autopilot?</h2>
      <p>Connect your first router in minutes. No credit card needed on the free plan.</p>
      <a href="{{ route('register') }}" class="btn btn-light">Create Free Account <i class="bi bi-arrow-right"></i></a>
    </div>
  </div>
</section>

<footer>
  <div class="container foot">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <a class="logo" href="{{ route('landing') }}"><span class="logo-mark"><i class="bi bi-broadcast"></i></span>Hamnet</a>
      <span>&copy; {{ date('Y') }} Hamnet Systems. All rights reserved.</span>
    </div>
    <div class="foot-links"><a href="#features">Platform</a><a href="#pricing">Pricing</a><a href="{{ route('login') }}">Sign in</a></div>
  </div>
</footer>

<!-- UI behaviour (works even if 3D fails to load) -->
<script>
(()=>{
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover:hover) and (pointer:fine)').matches;
  const nav=document.getElementById('nav'),links=document.getElementById('navLinks'),btn=document.getElementById('menuBtn');
  const onScroll=()=>nav.classList.toggle('scrolled',window.scrollY>10);
  onScroll();addEventListener('scroll',onScroll,{passive:true});
  btn.addEventListener('click',()=>{const o=links.classList.toggle('open');nav.classList.toggle('open-menu',o)});
  links.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{links.classList.remove('open');nav.classList.remove('open-menu')}));

  const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}}),{threshold:.12});
  document.querySelectorAll('.reveal').forEach((el,i)=>{ el.style.transitionDelay=(el.classList.contains('card')||el.classList.contains('plan'))?((i%3)*70)+'ms':'0ms'; io.observe(el); });

  // Active step while scrolling (also drives the 3D globe)
  document.documentElement.dataset.step='0';
  const steps=[...document.querySelectorAll('.step')];
  steps[0]&&steps[0].classList.add('active');
  const so=new IntersectionObserver(es=>es.forEach(e=>{ if(e.isIntersecting){ steps.forEach(s=>s.classList.toggle('active',s===e.target)); document.documentElement.dataset.step=e.target.dataset.step; } }),{rootMargin:'-42% 0px -42% 0px'});
  steps.forEach(s=>so.observe(s));

  if(fine && !reduce){
    // 3D tilt on cards
    document.querySelectorAll('[data-tilt]').forEach(el=>{
      el.addEventListener('pointermove',e=>{
        const r=el.getBoundingClientRect(), x=(e.clientX-r.left)/r.width-.5, y=(e.clientY-r.top)/r.height-.5;
        el.style.transition='transform .12s ease-out';
        el.style.transform=`perspective(900px) rotateX(${(-y*6).toFixed(2)}deg) rotateY(${(x*8).toFixed(2)}deg) translateY(-5px)`;
      });
      el.addEventListener('pointerleave',()=>{ el.style.transition=''; el.style.transform=''; });
    });
    // Magnetic buttons
    document.querySelectorAll('.btn').forEach(b=>{
      b.addEventListener('pointermove',e=>{ const r=b.getBoundingClientRect(); b.style.transform=`translate(${((e.clientX-r.left)/r.width-.5)*10}px,${((e.clientY-r.top)/r.height-.5)*8}px)`; });
      b.addEventListener('pointerleave',()=>{ b.style.transform=''; });
    });
    // Cursor ring
    const cur=document.getElementById('cur'); let cx=-100,cy=-100,tx=-100,ty=-100;
    addEventListener('pointermove',e=>{ tx=e.clientX; ty=e.clientY; cur.classList.add('on'); cur.classList.toggle('big',!!e.target.closest('a,button,.card,.plan')); },{passive:true});
    document.addEventListener('mouseleave',()=>cur.classList.remove('on'));
    (function loop(){ cx+=(tx-cx)*.18; cy+=(ty-cy)*.18; cur.style.transform=`translate(${cx}px,${cy}px)`; requestAnimationFrame(loop); })();
  }
})();
</script>

<!-- 3D scene -->
<script type="module">
import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const MV = reduce ? 0 : 1;
const canvas = document.getElementById('bg');
const bar = document.getElementById('bar');

let renderer;
try{
  renderer = new THREE.WebGLRenderer({canvas, antialias:true, alpha:true, powerPreference:'high-performance'});
}catch(err){ canvas.remove(); throw err; }
renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.05;

const scene = new THREE.Scene();
scene.fog = new THREE.Fog(0xf5f6fa, 13, 34);
const camera = new THREE.PerspectiveCamera(40, 1, .1, 100);
camera.position.set(0, 0, 14);

const pmrem = new THREE.PMREMGenerator(renderer);
scene.environment = pmrem.fromScene(new RoomEnvironment(), .04).texture;
scene.add(new THREE.AmbientLight(0xffffff, .55));
const sun = new THREE.DirectionalLight(0xffffff, 2.2); sun.position.set(-4, 6, 8); scene.add(sun);
const pBrand = new THREE.PointLight(0x4f46e5, 60, 0, 2); pBrand.position.set(5, 2, 5); scene.add(pBrand);
const pCyan  = new THREE.PointLight(0x06b6d4, 45, 0, 2); pCyan.position.set(-5, -2, 4); scene.add(pCyan);

/* ---------- Flat colours & materials (no gradients) ---------- */
const BRAND = 0x4f46e5, CYAN = 0x06b6d4, GREEN = 0x10b981, GOLD = 0xf5b301, LAV = 0x9aa3e8;
const mBody  = new THREE.MeshStandardMaterial({color:0x1a2138, roughness:.32, metalness:.55});
const mPlate = new THREE.MeshStandardMaterial({color:0x262f4d, roughness:.45, metalness:.4});
const mBrand = new THREE.MeshStandardMaterial({color:BRAND, roughness:.3, metalness:.3, emissive:BRAND, emissiveIntensity:.35});
const mGold  = new THREE.MeshStandardMaterial({color:GOLD, roughness:.25, metalness:1});
const mLight = new THREE.MeshStandardMaterial({color:0xe9ecff, roughness:.35, metalness:.2});
const basic = (c,o=1)=>new THREE.MeshBasicMaterial({color:c, transparent:o<1, opacity:o});
const noRay = o=>{ o.raycast=()=>{}; return o; };

function wifiArc(r, tube, mat, arc=Math.PI*.7){
  const m = new THREE.Mesh(new THREE.TorusGeometry(r, tube, 8, 56, arc), mat);
  m.rotation.z = Math.PI/2 - arc/2;
  return m;
}

/* ---------- Devices that connect to the router ---------- */
function makeDevice(kind){
  const g = new THREE.Group();
  if(kind==='phone'){
    g.add(new THREE.Mesh(new RoundedBoxGeometry(.36,.66,.05,3,.05), mBody));
    const s = new THREE.Mesh(new THREE.PlaneGeometry(.31,.58), basic(0xeef0ff)); s.position.z=.028; g.add(s);
    const b = new THREE.Mesh(new THREE.PlaneGeometry(.2,.06), basic(BRAND)); b.position.set(0,-.1,.03); g.add(b);
  } else if(kind==='tablet'){
    g.add(new THREE.Mesh(new RoundedBoxGeometry(.68,.9,.05,3,.05), mBody));
    const s = new THREE.Mesh(new THREE.PlaneGeometry(.6,.82), basic(0xeef0ff)); s.position.z=.028; g.add(s);
    const b = new THREE.Mesh(new THREE.PlaneGeometry(.4,.07), basic(CYAN)); b.position.set(0,.2,.03); g.add(b);
    const c = new THREE.Mesh(new THREE.PlaneGeometry(.4,.04), basic(0xc3c9e6)); c.position.set(0,0,.03); g.add(c);
  } else { // laptop
    const base = new THREE.Mesh(new RoundedBoxGeometry(.95,.05,.64,2,.02), mPlate); base.position.y=-.3; g.add(base);
    const hinge = new THREE.Group(); hinge.position.set(0,-.28,-.3); hinge.rotation.x=-.22; g.add(hinge);
    const lid = new THREE.Mesh(new RoundedBoxGeometry(.95,.6,.04,2,.02), mBody); lid.position.y=.3; hinge.add(lid);
    const s = new THREE.Mesh(new THREE.PlaneGeometry(.85,.5), basic(0xeef0ff)); s.position.set(0,.3,.025); hinge.add(s);
    const b = new THREE.Mesh(new THREE.PlaneGeometry(.5,.07), basic(BRAND)); b.position.set(-.1,.4,.03); hinge.add(b);
    const c = new THREE.Mesh(new THREE.PlaneGeometry(.7,.04), basic(0xc3c9e6)); c.position.set(0,.25,.03); hinge.add(c);
  }
  return g;
}

/* ---------- 1. Router + connected devices + signal rings ---------- */
function buildRouter(){
  const root = new THREE.Group(), g = new THREE.Group(); g.position.y = -1.2; root.add(g);
  const body = new THREE.Mesh(new RoundedBoxGeometry(2.4,.4,1.3,4,.12), mBody); body.position.y = -.5; g.add(body);
  const plate = new THREE.Mesh(new RoundedBoxGeometry(2.2,.06,1.1,3,.03), mPlate); plate.position.y = -.28; g.add(plate);
  const leds=[];
  for(let i=0;i<4;i++){
    const l = new THREE.Mesh(new THREE.BoxGeometry(.12,.05,.05), basic(i===0?GREEN:CYAN));
    l.position.set(-.75+i*.28,-.5,.66); g.add(l); leds.push(l);
  }
  for(let i=0;i<3;i++){
    const p = new THREE.Mesh(new THREE.BoxGeometry(.2,.1,.04), basic(0x0a0f1d));
    p.position.set(.45+i*.28,-.5,.66); g.add(p);
  }
  const ants=[];
  [-.9,-.3,.3,.9].forEach((x,i)=>{
    const pv = new THREE.Group(); pv.position.set(x,-.3,-.35);
    const rod = new THREE.Mesh(new THREE.CylinderGeometry(.045,.06,1.3,16), mBody); rod.position.y=.65;
    const tip = new THREE.Mesh(new THREE.SphereGeometry(.07,16,16), mBrand); tip.position.y=1.32;
    pv.add(rod,tip); pv.rotation.z = (i-1.5)*-.08; pv.userData.base = pv.rotation.z; g.add(pv); ants.push(pv);
  });
  const arcs=[], arcMats=[];
  for(let i=0;i<3;i++){
    const mat = basic(BRAND, 1); const a = wifiArc(.6+i*.5, .035, mat);
    a.position.y = 1.55; g.add(a); arcs.push(a); arcMats.push(mat);
  }
  g.add(new THREE.Mesh(new THREE.SphereGeometry(.08,16,16), basic(BRAND))).position.y = 1.55;

  // ground signal rings
  const rings=[];
  for(let i=0;i<4;i++){
    const mt = basic(BRAND,.4);
    const r = noRay(new THREE.Mesh(new THREE.RingGeometry(.97,1,72), mt));
    r.rotation.x = -Math.PI/2; r.position.y = -1.78; root.add(r); rings.push({r, mt});
  }
  // devices orbiting and linked by beams
  const kinds = ['laptop','phone','tablet','phone','laptop'];
  const devs = kinds.map((k,i)=>{
    const dg = makeDevice(k); root.add(dg);
    const pos = new Float32Array(6), geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos,3));
    const line = noRay(new THREE.Line(geo, new THREE.LineBasicMaterial({color:BRAND, transparent:true, opacity:.5})));
    line.frustumCulled = false; root.add(line);
    const pkt = noRay(new THREE.Mesh(new THREE.SphereGeometry(.06,10,10), basic(CYAN))); root.add(pkt);
    return {g:dg, line, geo, pos, pkt, a:(i/kinds.length)*Math.PI*2, r:2.35+(i%2)*.55, y:-.9+((i*37)%10)/10*2.1, sp:(.22+.07*(i%3))*(i%2?-1:1), ph:i*1.7, k:Math.random()};
  });
  root.userData = {g, ants, arcs, arcMats, leds, rings, devs, bursts:[], surge:0};
  return root;
}

/* ---------- 2. Server rack with rising data packets ---------- */
function buildRack(){
  const root = new THREE.Group(), g = new THREE.Group(); g.position.y = -.95; root.add(g);
  const leds=[];
  for(let i=0;i<4;i++){
    const u = new THREE.Mesh(new RoundedBoxGeometry(1.9,.42,1.1,3,.06), mBody); u.position.y = i*.52; g.add(u);
    for(let v=0;v<6;v++){ const vent = new THREE.Mesh(new THREE.BoxGeometry(.04,.2,.02), mPlate); vent.position.set(-.1+v*.1,i*.52,.56); g.add(vent); }
    for(let k=0;k<3;k++){
      const l = new THREE.Mesh(new THREE.BoxGeometry(.07,.07,.03), basic(k===0?GREEN:CYAN));
      l.position.set(-.78+k*.14,i*.52,.56); g.add(l); leds.push({m:l, ph:Math.random()*6});
    }
    const slot = new THREE.Mesh(new THREE.BoxGeometry(.42,.1,.02), basic(BRAND)); slot.position.set(.65,i*.52,.56); g.add(slot);
  }
  [-.98,.98].forEach(x=>{ const post = new THREE.Mesh(new THREE.CylinderGeometry(.04,.04,2.4,12), mPlate); post.position.set(x,.78,.5); g.add(post); });
  const ant = new THREE.Mesh(new THREE.CylinderGeometry(.03,.04,.5,12), mBody); ant.position.set(0,2.35,0); g.add(ant);
  const mats=[];
  for(let i=0;i<3;i++){ const mt=basic(CYAN); const a=wifiArc(.3+i*.28,.025,mt); a.position.set(0,2.7,0); g.add(a); mats.push(mt); }
  const pk=[];
  for(let i=0;i<7;i++){ const s = new THREE.Mesh(new THREE.SphereGeometry(.05,10,10), basic(i%2?CYAN:BRAND)); s.position.x = (i%2?-1:1)*1.22; g.add(s); pk.push({s, t:Math.random(), v:.25+Math.random()*.3}); }
  root.userData = {leds, arcMats:mats, pk};
  return root;
}

/* ---------- 3. Globe: orbiting satellites + arcs with data pulses ---------- */
function buildGlobe(){
  const root = new THREE.Group();
  const core = new THREE.Mesh(new THREE.SphereGeometry(.92,40,40), mBody); root.add(core);
  const wire = new THREE.Mesh(new THREE.IcosahedronGeometry(1.12,2), new THREE.MeshBasicMaterial({color:BRAND, wireframe:true, transparent:true, opacity:.6}));
  root.add(wire);
  const dots=[];
  for(let i=0;i<16;i++){
    const v = new THREE.Vector3().randomDirection().multiplyScalar(1.14);
    const d = new THREE.Mesh(new THREE.SphereGeometry(.05,10,10), basic(i%3===0?GREEN:CYAN)); d.position.copy(v); root.add(d); dots.push(d);
  }
  const links=[];
  for(let i=0;i<8;i++){
    const a = dots[i].position.clone().normalize(), b = dots[(i*3+5)%16].position.clone().normalize();
    if(a.distanceTo(b)<.3) continue;
    const mid = a.clone().add(b).normalize().multiplyScalar(1.15+a.distanceTo(b)*.55);
    const curve = new THREE.QuadraticBezierCurve3(a.clone().multiplyScalar(1.14), mid, b.clone().multiplyScalar(1.14));
    const line = new THREE.Line(new THREE.BufferGeometry().setFromPoints(curve.getPoints(44)), new THREE.LineBasicMaterial({color:CYAN, transparent:true, opacity:.55}));
    const pk = new THREE.Mesh(new THREE.SphereGeometry(.045,8,8), basic(0xffffff));
    root.add(line, pk); links.push({curve, pk, t:Math.random(), s:.16+Math.random()*.22});
  }
  const orbits=[];
  [[1.75,.4,0,.9],[1.55,-.7,.5,-.7],[1.95,1.1,-.4,.5]].forEach(([r,rx,rz,sp],i)=>{
    const pivot = new THREE.Group(); pivot.rotation.set(rx,0,rz);
    const ring = new THREE.Mesh(new THREE.TorusGeometry(r,.012,6,120), basic(LAV,.7)); ring.rotation.x = Math.PI/2; pivot.add(ring);
    const spin = new THREE.Group();
    const sat = new THREE.Mesh(new THREE.SphereGeometry(.1,16,16), i===1?mBrand:mGold); sat.position.x = r; spin.add(sat);
    const halo = new THREE.Mesh(new THREE.RingGeometry(.14,.17,24), basic(BRAND,.6)); halo.position.x=r; spin.add(halo);
    pivot.add(spin); root.add(pivot); orbits.push({spin, sp, halo});
  });
  const spinner = new THREE.Group(); // everything that turns with the scroll step
  [core,wire,...dots].forEach(o=>{ root.remove(o); spinner.add(o); });
  links.forEach(l=>{ root.remove(l.pk); });
  root.add(spinner);
  root.userData = {wire, core, orbits, dots, links, spinner};
  return root;
}

/* ---------- 4. Phone + MoMo coin + "paid" badge ---------- */
function buildPhone(){
  const root = new THREE.Group();
  root.add(new THREE.Mesh(new RoundedBoxGeometry(.95,1.8,.13,4,.12), mBody));
  const scr = new THREE.Mesh(new THREE.PlaneGeometry(.82,1.66), basic(0xeef0ff)); scr.position.z=.07; root.add(scr);
  const notch = new THREE.Mesh(new THREE.PlaneGeometry(.26,.05), basic(0x1a2138)); notch.position.set(0,.77,.075); root.add(notch);
  const w = new THREE.Group(); w.position.set(0,.28,.08);
  const wm=[];
  [.12,.24,.36].forEach(r=>{ const mt=basic(BRAND); const a=wifiArc(r,.022,mt,Math.PI*.65); a.position.y=-.05; w.add(a); wm.push(mt); });
  const wd = new THREE.Mesh(new THREE.CircleGeometry(.035,16), basic(BRAND)); wd.position.y=-.05; w.add(wd); root.add(w);
  const bt = new THREE.Mesh(new RoundedBoxGeometry(.56,.16,.02,2,.01), basic(BRAND)); bt.position.set(0,-.3,.08); root.add(bt);
  const l1 = new THREE.Mesh(new THREE.PlaneGeometry(.5,.05), basic(0xc3c9e6)); l1.position.set(0,-.55,.075); root.add(l1);
  const l2 = new THREE.Mesh(new THREE.PlaneGeometry(.34,.05), basic(0xc3c9e6)); l2.position.set(-.08,-.67,.075); root.add(l2);
  const pulse = new THREE.Mesh(new THREE.RingGeometry(1.05,1.08,64), basic(BRAND,.5)); pulse.position.z=-.1; root.add(pulse);
  const pulse2 = new THREE.Mesh(new THREE.RingGeometry(1.05,1.08,64), basic(CYAN,.5)); pulse2.position.z=-.1; root.add(pulse2);
  const coin = new THREE.Group(); coin.position.set(1.15,-.45,.45);
  const cm = new THREE.Mesh(new THREE.CylinderGeometry(.42,.42,.09,48), mGold); cm.rotation.x = Math.PI/2; coin.add(cm);
  const rm = new THREE.MeshStandardMaterial({color:0xb98200, roughness:.3, metalness:1});
  const cr = new THREE.Mesh(new THREE.TorusGeometry(.31,.02,8,48), rm); cr.position.z=.05; coin.add(cr);
  const cr2 = cr.clone(); cr2.position.z=-.05; coin.add(cr2);
  root.add(coin);
  const badge = new THREE.Group(); badge.position.set(.45,.78,.2);
  badge.add(new THREE.Mesh(new THREE.CircleGeometry(.22,32), basic(GREEN)));
  const c1 = new THREE.Mesh(new THREE.BoxGeometry(.07,.17,.02), basic(0xffffff)); c1.position.set(-.05,-.02,.02); c1.rotation.z = .8; badge.add(c1);
  const c2 = new THREE.Mesh(new THREE.BoxGeometry(.07,.3,.02), basic(0xffffff)); c2.position.set(.06,.02,.02); c2.rotation.z = -.7; badge.add(c2);
  badge.scale.setScalar(0); root.add(badge);
  root.userData = {coin, pulse, pulse2, wm, badge};
  return root;
}

const router = buildRouter(), rack = buildRack(), globe = buildGlobe(), phone = buildPhone();
[router, rack, globe, phone].forEach(o=>{ o.visible=false; scene.add(o); });
// globe link pulses live in the globe's own space (they rotate with the spinner)
globe.userData.links.forEach(l=>globe.userData.spinner.add(l.pk));

/* ---------- Wi-Fi wave field (floor), reacts to mouse, clicks & scroll ---------- */
const FLOOR = -4.2, GX = innerWidth<760?44:66, GZ = innerWidth<760?22:34;
const wPos = new Float32Array(GX*GZ*3), wCol = new Float32Array(GX*GZ*3);
for(let iz=0;iz<GZ;iz++) for(let ix=0;ix<GX;ix++){
  const k=(iz*GX+ix)*3; wPos[k]=(ix/(GX-1)-.5)*32; wPos[k+1]=0; wPos[k+2]=-17+(iz/(GZ-1))*20;
}
const wGeo = new THREE.BufferGeometry();
wGeo.setAttribute('position', new THREE.BufferAttribute(wPos,3));
wGeo.setAttribute('color', new THREE.BufferAttribute(wCol,3));
const wave = new THREE.Points(wGeo, new THREE.PointsMaterial({size:.085, vertexColors:true, transparent:true, opacity:.9}));
wave.position.y = FLOOR; wave.frustumCulled = false; scene.add(wave);
const cLav = new THREE.Color(LAV), cBrand = new THREE.Color(BRAND), cCyan = new THREE.Color(CYAN);
const ripples = [];
function addRipple(x,z,a){ ripples.push({x,z,t0:performance.now()/1000,a}); if(ripples.length>8) ripples.shift(); }

/* ---------- Floating network (nodes + links + packets) ---------- */
const mobile = innerWidth < 760;
const N = mobile ? 46 : 92;
const spreadX = 7.5 * Math.max(innerWidth/innerHeight, 1.1);
const nodes=[];
for(let i=0;i<N;i++){
  const base = new THREE.Vector3((Math.random()*2-1)*spreadX, -14 + Math.random()*21, -7 + Math.random()*8);
  nodes.push({base, p:base.clone(), h:0, ph:Math.random()*6.28, depth:(base.z+7)/8, col:new THREE.Color(i%9===0?CYAN:0x8c94ea)});
}
const edgeSet=new Set(), edges=[];
nodes.forEach((a,i)=>{
  nodes.map((b,j)=>({j,d:a.base.distanceTo(b.base)})).filter(o=>o.j!==i).sort((x,y)=>x.d-y.d).slice(0,3).forEach(o=>{
    const k = i<o.j ? i+'-'+o.j : o.j+'-'+i;
    if(!edgeSet.has(k) && o.d<5.5){ edgeSet.add(k); edges.push([i,o.j]); }
  });
});
const nodeMesh = new THREE.InstancedMesh(new THREE.SphereGeometry(.075,14,14), new THREE.MeshBasicMaterial({color:0xffffff}), N);
nodeMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
nodeMesh.instanceColor = new THREE.InstancedBufferAttribute(new Float32Array(N*3),3);
nodeMesh.frustumCulled = false; scene.add(nodeMesh);
const edgePos = new Float32Array(edges.length*6);
const lineGeo = new THREE.BufferGeometry(); lineGeo.setAttribute('position', new THREE.BufferAttribute(edgePos,3));
const lines = new THREE.LineSegments(lineGeo, new THREE.LineBasicMaterial({color:0xaab1ee, transparent:true, opacity:.55}));
lines.frustumCulled=false; scene.add(lines);

const MAXL=8, linkPos=new Float32Array(MAXL*6);
const linkGeo=new THREE.BufferGeometry(); linkGeo.setAttribute('position', new THREE.BufferAttribute(linkPos,3)); linkGeo.setDrawRange(0,0);
const links3d=new THREE.LineSegments(linkGeo,new THREE.LineBasicMaterial({color:BRAND,transparent:true,opacity:.75}));
links3d.frustumCulled=false; scene.add(links3d);

const packets=[], pktMat = basic(CYAN);
for(let i=0;i<(mobile?8:18);i++){
  const m = new THREE.Mesh(new THREE.SphereGeometry(.07,10,10), pktMat); m.frustumCulled=false; scene.add(m);
  packets.push({m, e:Math.floor(Math.random()*edges.length), t:Math.random(), s:.25+Math.random()*.5, dir:Math.random()<.5?1:-1});
}

/* ---------- Anchors ---------- */
const $ = s=>document.querySelector(s);
const anchors = [
  {el:$('#a-router'), obj:router, size:5.4, yaw:-.35, pitch:.14, spin:0,   ph:0,   follow:1},
  {el:$('#a-rack'),   obj:rack,   size:3.4, yaw:.5,   pitch:.1,  spin:0,   ph:1.7, follow:1},
  {el:$('#a-globe'),  obj:globe,  size:4.1, yaw:0,    pitch:.2,  spin:0,   ph:3.1, follow:.5},
  {el:$('#a-phone'),  obj:phone,  size:2.2, yaw:-.45, pitch:.05, spin:0,   ph:4.4, follow:1},
].filter(a=>a.el);
anchors.forEach(a=>{ a.s=0; a.first=true; });

/* ---------- Input ---------- */
const mouse={x:9,y:9,sx:9,sy:9,has:false};
let lastRip=0, lastMx=0;
addEventListener('pointermove',e=>{
  mouse.x=e.clientX/innerWidth*2-1; mouse.y=-(e.clientY/innerHeight*2-1); mouse.has=true;
  const now=performance.now();
  if(MV && now-lastRip>170 && Math.abs(e.clientX-lastMx)>36){ lastRip=now; lastMx=e.clientX; addRipple(mouse.x*12, -6+Math.random()*3, .5); }
},{passive:true});
document.addEventListener('mouseleave',()=>{ mouse.has=false; });
const raycaster = new THREE.Raycaster();
let hover=false, hoverK=0;
addEventListener('click',()=>{
  if(!MV) return;
  addRipple(mouse.x*12, -5, 1.3);
  if(hover && router.visible) spawnBurst();
});
function spawnBurst(){
  const u=router.userData; u.surge=1;
  for(let i=0;i<3;i++){
    const mt = basic(CYAN,1); const a = wifiArc(.5,.05,mt,Math.PI*.7); a.position.y=1.55; noRay(a);
    a.userData={t0:performance.now()+i*180, mt};
    u.g.add(a); u.bursts.push(a);
  }
}

/* ---------- Helpers ---------- */
const _v=new THREE.Vector3(), _d=new THREE.Vector3();
function ndcToZ0(nx,ny,out){
  _v.set(nx,ny,.5).unproject(camera); _d.copy(_v).sub(camera.position).normalize();
  out.copy(camera.position).addScaledVector(_d, -camera.position.z/_d.z); return out;
}
const lerp=THREE.MathUtils.lerp, tan=Math.tan(THREE.MathUtils.degToRad(camera.fov/2));
const _m=new THREE.Matrix4(), _q=new THREE.Quaternion(), _sc=new THREE.Vector3(), _col=new THREE.Color(), _brand=new THREE.Color(BRAND);
const P=new THREE.Vector3(), tmpP=new THREE.Vector3(), _c=new THREE.Vector3();
const clamp01=x=>Math.max(0,Math.min(1,x));

function resize(){
  renderer.setSize(innerWidth, innerHeight, false);
  camera.aspect = innerWidth/innerHeight; camera.updateProjectionMatrix(); camera.updateMatrixWorld();
}
addEventListener('resize', resize); resize();

/* ---------- Loop ---------- */
let last=performance.now(), t=0, lastY=scrollY, sv=0, stepYaw=0, curStep=0;
function frame(now){
  const dt=Math.min((now-last)/1000,.05); last=now; t+=dt;
  const dy=Math.abs(scrollY-lastY); lastY=scrollY; sv=lerp(sv,Math.min(dy,60),.1);
  const boost = 1 + sv*.08;
  const ease = (()=>{const i=Math.min(1,t/1.2); return 1-Math.pow(1-i,3);})();
  mouse.sx=lerp(mouse.sx,mouse.x,.12); mouse.sy=lerp(mouse.sy,mouse.y,.12);
  const maxS = Math.max(1, document.documentElement.scrollHeight - innerHeight);
  bar.style.transform = `scaleX(${Math.min(1,scrollY/maxS)})`;
  curStep = +document.documentElement.dataset.step || 0;
  stepYaw = lerp(stepYaw, curStep*1.25, .05);

  /* wave field */
  const tt = now/1000, sy = scrollY;
  for(let i=0;i<ripples.length;i++){ if(tt-ripples[i].t0>5){ ripples.splice(i,1); i--; } }
  for(let i=0;i<GX*GZ;i++){
    const k=i*3, x=wPos[k], z=wPos[k+2];
    let h = (Math.sin(x*.35+t*.6)*.12 + Math.cos(z*.5-t*.5)*.12 + Math.sin(z*.55 - sy*.005)*.2)*MV;
    const d0 = Math.hypot(x, z+7);
    h += Math.sin(d0*1.3 - t*2.2*MV)*.32*Math.exp(-d0*.16)*MV;
    for(let r=0;r<ripples.length;r++){
      const R=ripples[r], age=tt-R.t0, d=Math.hypot(x-R.x, z-R.z), w=d-age*5.5;
      h += Math.cos(w*1.5)*Math.exp(-w*w*.22)*R.a*Math.exp(-age*.55);
    }
    wPos[k+1]=h;
    const c = h>.5 ? cCyan : (h>.26 ? cBrand : cLav);
    wCol[k]=c.r; wCol[k+1]=c.g; wCol[k+2]=c.b;
  }
  wGeo.attributes.position.needsUpdate=true; wGeo.attributes.color.needsUpdate=true;

  /* floating network */
  const aspect=camera.aspect;
  ndcToZ0(mouse.sx, mouse.sy, P);
  const hot=[];
  for(let i=0;i<N;i++){
    const n=nodes[i];
    const shift = scrollY*(.0007+.0013*n.depth);
    n.p.set(n.base.x+Math.sin(t*.4+n.ph)*.22*MV, n.base.y+shift+Math.cos(t*.5+n.ph)*.22*MV, n.base.z);
    tmpP.copy(n.p).project(camera);
    const dx=(tmpP.x-mouse.sx)*aspect, dyy=tmpP.y-mouse.sy, d=Math.hypot(dx,dyy);
    const target = (mouse.has && MV) ? Math.max(0,1-d/.42) : 0;
    n.h += (target-n.h)*.12;
    if(n.h>.002 && d>1e-4){ n.p.x -= (dx/d)*n.h*.35; n.p.y -= (dyy/d)*n.h*.35; }
    if(n.h>.18) hot.push(n);
    const s=1+n.h*2.6;
    _sc.set(s,s,s); _m.compose(n.p,_q,_sc); nodeMesh.setMatrixAt(i,_m);
    _col.copy(n.col).lerp(_brand,n.h); nodeMesh.setColorAt(i,_col);
  }
  nodeMesh.instanceMatrix.needsUpdate=true; nodeMesh.instanceColor.needsUpdate=true;
  for(let e=0;e<edges.length;e++){
    const a=nodes[edges[e][0]].p, b=nodes[edges[e][1]].p, k=e*6;
    edgePos[k]=a.x;edgePos[k+1]=a.y;edgePos[k+2]=a.z;edgePos[k+3]=b.x;edgePos[k+4]=b.y;edgePos[k+5]=b.z;
  }
  lineGeo.attributes.position.needsUpdate=true;
  hot.sort((a,b)=>b.h-a.h); const L=Math.min(MAXL,hot.length);
  for(let i=0;i<L;i++){ const k=i*6, p=hot[i].p; linkPos[k]=P.x;linkPos[k+1]=P.y;linkPos[k+2]=P.z;linkPos[k+3]=p.x;linkPos[k+4]=p.y;linkPos[k+5]=p.z; }
  linkGeo.setDrawRange(0,L*2); linkGeo.attributes.position.needsUpdate=true;

  for(const k of packets){
    k.t += dt*k.s*boost*k.dir*MV;
    if(k.t>1||k.t<0){ k.e=Math.floor(Math.random()*edges.length); k.t=k.dir>0?0:1; }
    const ed=edges[k.e]; if(!ed) continue;
    k.m.position.lerpVectors(nodes[ed[0]].p, nodes[ed[1]].p, k.t);
  }

  /* hover on router (includes its devices) */
  if(router.visible && mouse.has && MV){
    raycaster.setFromCamera({x:mouse.x,y:mouse.y}, camera);
    hover = raycaster.intersectObject(router,true).length>0;
  } else hover=false;
  hoverK = lerp(hoverK, hover?1:0, .1);
  document.body.style.cursor = hover ? 'pointer' : '';

  /* place objects on their DOM anchors */
  const vH=2*tan*camera.position.z, vW=vH*aspect;
  for(const a of anchors){
    const r=a.el.getBoundingClientRect();
    const vis = r.width>0 && r.bottom>-160 && r.top<innerHeight+160;
    a.obj.visible = vis; if(!vis) continue;
    const wH=r.height/innerHeight*vH, wW=r.width/innerWidth*vW;
    const target=Math.min(wH,wW)/a.size;
    a.s = a.first ? target : lerp(a.s,target,.2); a.first=false;
    ndcToZ0(((r.left+r.width/2)/innerWidth)*2-1, -(((r.top+r.height/2)/innerHeight)*2-1), a.obj.position);
    a.obj.position.y += Math.sin(t*1.1+a.ph)*.14*a.s*MV;
    a.obj.scale.setScalar(a.s*ease);
    const p=((r.top+r.height/2)/innerHeight)-.5;
    const ty = a.yaw + (mouse.has?mouse.sx*.6*MV*a.follow:0) + p*1.2;
    const tx = a.pitch - (mouse.has?mouse.sy*.28*MV*a.follow:0) - p*.18;
    a.obj.rotation.y = lerp(a.obj.rotation.y, ty, .08);
    a.obj.rotation.x = lerp(a.obj.rotation.x, tx, .08);
  }

  /* router + devices */
  if(router.visible){
    const u=router.userData, sp=(1+hoverK*1.8)*boost; u.surge = Math.max(0,u.surge-dt*.8);
    u.arcMats.forEach((m,i)=>{ m.opacity = .15 + .85*(.5+.5*Math.sin(t*3*sp - i*1.15)); });
    u.arcs.forEach((a,i)=>{ a.scale.setScalar(1+.04*Math.sin(t*3*sp-i*1.15)*MV); });
    u.ants.forEach((p,i)=>{ p.rotation.z = p.userData.base + Math.sin(t*7+i)*.07*hoverK; });
    u.leds.forEach((l,i)=>{ l.visible = hoverK>.3 ? Math.sin(t*14+i*1.7)>-.2 : (i===0 || Math.sin(t*2+i*2)>-.7 || !MV); });
    u.rings.forEach((o,i)=>{ const k=((t*.35*sp*MV + i/4)%1+1)%1; o.r.scale.setScalar(.9+k*4.2); o.mt.opacity=(1-k)*(.4+.5*u.surge+.2*hoverK); });
    u.devs.forEach(d=>{
      d.a += dt*d.sp*sp*MV;
      const x=Math.cos(d.a)*d.r, z=Math.sin(d.a)*d.r, y=d.y+Math.sin(t*1.3+d.ph)*.1*MV;
      d.g.position.set(x,y,z); d.g.rotation.y=Math.PI/2-d.a; d.g.rotation.x=Math.sin(t*.9+d.ph)*.08*MV;
      d.pos[0]=0; d.pos[1]=.35; d.pos[2]=0; d.pos[3]=x; d.pos[4]=y; d.pos[5]=z; d.geo.attributes.position.needsUpdate=true;
      d.line.material.opacity = Math.min(1,(.22+.35*(.5+.5*Math.sin(t*4+d.ph)))*(1+hoverK*1.2) + u.surge*.6);
      d.k=(d.k+dt*(.45+hoverK*.9+u.surge*1.5)*MV)%1;
      const kk = d.k<.5 ? d.k*2 : 2-d.k*2;
      d.pkt.position.set(x*kk, .35+(y-.35)*kk, z*kk);
    });
    for(let i=u.bursts.length-1;i>=0;i--){
      const b=u.bursts[i], age=(performance.now()-b.userData.t0)/1000;
      if(age<0){ b.visible=false; continue; } b.visible=true;
      if(age>1.4){ u.g.remove(b); b.geometry.dispose(); u.bursts.splice(i,1); continue; }
      b.scale.setScalar(1+age*3.2); b.userData.mt.opacity=1-age/1.4;
    }
  }
  if(rack.visible){
    const u=rack.userData;
    u.leds.forEach(l=>{ l.m.visible = !MV || Math.sin(t*6+l.ph*5)>-.55; });
    u.arcMats.forEach((m,i)=>{ m.opacity=.2+.8*(.5+.5*Math.sin(t*3.2*boost-i*1.1)); });
    u.pk.forEach(p=>{ p.t=(p.t+dt*p.v*boost*MV)%1; p.s.position.y=-.2+p.t*2.5; p.s.scale.setScalar(Math.sin(p.t*Math.PI)); });
  }
  if(globe.visible){
    const u=globe.userData;
    u.spinner.rotation.y = lerp(u.spinner.rotation.y, stepYaw + t*.12*MV, .1);
    u.wire.rotation.y += dt*.25*boost*MV; u.wire.rotation.x += dt*.07*MV;
    u.orbits.forEach(o=>{ o.spin.rotation.y += dt*o.sp*boost*MV; o.halo.rotation.z += dt*MV; o.halo.lookAt(camera.position); });
    u.links.forEach(l=>{ l.t=(l.t+dt*l.s*boost*MV)%1; l.pk.position.copy(l.curve.getPoint(l.t)); });
  }
  if(phone.visible){
    const u=phone.userData;
    u.coin.rotation.y += dt*1.6*boost*MV; u.coin.position.y = -.45+Math.sin(t*1.6)*.1*MV;
    let k=((t*.7)%1); u.pulse.scale.setScalar(1+k*.9); u.pulse.material.opacity=.5*(1-k);
    k=((t*.7+.5)%1); u.pulse2.scale.setScalar(1+k*.9); u.pulse2.material.opacity=.4*(1-k);
    u.wm.forEach((m,i)=>{ m.opacity=.25+.75*(.5+.5*Math.sin(t*3-i*1.1)); });
    const c=(t%3.4)/3.4; let s = c<.1 ? c/.1*1.15 : c<.14 ? 1.15-(c-.1)/.04*.15 : c<.72 ? 1 : Math.max(0,1-(c-.72)/.12);
    u.badge.scale.setScalar(MV?s:1);
  }

  camera.position.x = lerp(camera.position.x, mouse.has?mouse.sx*.5*MV:0, .05);
  camera.position.y = lerp(camera.position.y, mouse.has?mouse.sy*.3*MV:0, .05);
  camera.lookAt(0,0,0); camera.updateMatrixWorld();

  renderer.render(scene,camera);
  requestAnimationFrame(frame);
}
requestAnimationFrame(frame);
</script>
</body>
</html>
