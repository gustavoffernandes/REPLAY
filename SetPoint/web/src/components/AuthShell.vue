<script setup></script>

<template>
  <div class="shell">
    <aside class="brand">
      <div class="logo"><span class="dot"></span>SET<b>POINT</b></div>
      <div class="pitch">
        <h2>Suas quadras de areia,<br /><span>do agendamento ao caixa.</span></h2>
        <ul>
          <li>Reservas online com sinal via Pix</li>
          <li>Agenda por quadra, turmas e mensalistas</li>
          <li>Várias filiais, cada uma com seu acesso</li>
        </ul>
      </div>
      <svg class="court" viewBox="0 0 200 120" aria-hidden="true">
        <rect x="4" y="4" width="192" height="112" rx="6" fill="none" stroke="currentColor" stroke-width="1.5" />
        <line x1="100" y1="4" x2="100" y2="116" stroke="currentColor" stroke-width="1.5" />
        <rect x="46" y="30" width="108" height="60" fill="none" stroke="currentColor" stroke-width="1" opacity=".5" />
      </svg>
    </aside>

    <main class="panel">
      <div class="top"><div class="logo small"><span class="dot"></span>SET<b>POINT</b></div></div>
      <div class="box"><slot /></div>
      <p class="foot muted">Conexão protegida · Suas informações são tratadas com sigilo</p>
    </main>
  </div>
</template>

<style scoped>
.shell { min-height: 100vh; display: grid; grid-template-columns: minmax(340px, 46%) 1fr; }
.brand { position: relative; overflow: hidden; padding: 40px; display: flex; flex-direction: column; justify-content: space-between; border-right: 1px solid var(--border); background: radial-gradient(700px 500px at 0% 0%, rgba(180, 255, 31, 0.14), transparent 60%), var(--surface); }
.logo { font-weight: 500; font-size: 20px; letter-spacing: .14em; display: flex; align-items: center; gap: 10px; }
.logo b { color: var(--neon); font-weight: 700; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--neon); box-shadow: 0 0 14px var(--neon); }
.pitch h2 { font-size: 34px; line-height: 1.15; letter-spacing: -0.02em; }
.pitch h2 span { color: var(--neon); }
.pitch ul { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 10px; color: var(--muted); }
.pitch li::before { content: ''; display: inline-block; width: 6px; height: 6px; margin-right: 10px; border-radius: 50%; background: var(--neon); vertical-align: middle; }
.court { position: absolute; right: -40px; bottom: -30px; width: 340px; color: var(--neon); opacity: .12; transform: rotate(-8deg); }
.panel { display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 32px 20px; gap: 24px; position: relative; }
.top { display: none; }
.box { width: 100%; max-width: 400px; }
.foot { font-size: 12px; text-align: center; }
@media (max-width: 860px) {
  .shell { grid-template-columns: 1fr; }
  .brand { display: none; }
  .panel { justify-content: flex-start; padding-top: 48px; background: radial-gradient(700px 360px at 50% -10%, rgba(180, 255, 31, 0.1), transparent 60%); }
  .top { display: block; }
}
</style>
