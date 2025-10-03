/* ===========================
   MODALE: apertura/chiusura
   =========================== */
const modal = document.getElementById('modalForm');
const closeModalBtn = document.getElementById('closeModal');
const openButtons = document.querySelectorAll('.openModalBtn');

function openModal() {
  if (!modal) return;
  modal.classList.add('show');
  modal.classList.remove('hide');
  document.body.style.overflow = 'hidden'; // evita scroll sotto la modale
}

function closeModal() {
  if (!modal) return;
  modal.classList.add('hide');
  setTimeout(() => {
    modal.classList.remove('show');
    document.body.style.overflow = '';
  }, 300);
}

openButtons.forEach(btn => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    openModal();
  });
});

if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);

if (modal) {
  // Chiudi cliccando fuori dal contenuto
  window.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  // Chiudi con ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
  });
}

/* ===========================
   FORM: invio al backend
   =========================== */
// Form dentro alla modale
const form = document.querySelector('#modalForm form');

// Endpoint backend: in locale (server PHP su 8001) vs produzione
const API_URL = (location.hostname === '127.0.0.1' || location.hostname === 'localhost')
  ? 'http://127.0.0.1:8001/backend/send-mail.php'
  : '../../backend/send-mail.php';

// helper messaggi
function ensureMsgBox(f) {
  let box = f.querySelector('.form-message');
  if (!box) {
    box = document.createElement('div');
    box.className = 'form-message';
    box.style.marginTop = '10px';
    box.style.fontSize = '0.95rem';
    f.appendChild(box);
  }
  return box;
}
function setMsg(box, text, ok = true) {
  box.textContent = text;
  box.style.color = ok ? '#2e7d32' : '#b00020';
}

if (form) {
  // se manca action, impostiamo noi quella corretta
  if (!form.getAttribute('action')) form.setAttribute('action', API_URL);

  form.addEventListener('submit', async (e) => {
    e.preventDefault(); // <-- impedisce il submit GET

    const msgBox = ensureMsgBox(form);
    const submitBtn = form.querySelector('button[type="submit"], .btn');
    const originalBtnText = submitBtn ? submitBtn.textContent : '';

    // Disabilita il bottone durante l’invio
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Invio...'; }
    setMsg(msgBox, 'Invio in corso...', true);

    // Raccogli i campi (i name devono essere: name, email, phone, message)
    const fd = new FormData(form);

    try {
      const res = await fetch(form.getAttribute('action'), {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        mode: 'cors' // ok anche in locale con CORS abilitato nel PHP
      });

      let data = {};
      try { data = await res.json(); } catch (_) {}

      if (!res.ok || !data.ok) {
        const err = data.error || 'Errore durante l’invio. Riprova.';
        setMsg(msgBox, err, false);
      } else {
        setMsg(msgBox, 'Richiesta inviata correttamente! Ti ricontatteremo a breve.', true);
        form.reset();
        setTimeout(closeModal, 1200);
      }
    } catch (error) {
      console.error(error);
      setMsg(msgBox, 'Connessione non riuscita. Controlla la rete e riprova.', false);
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalBtnText || 'Invia'; }
    }
  });
}

/* ===========================
   CARDS "Le nostre collezioni"
   Mobile centrato (override safe)
   =========================== */
(function fixCollectionsMobileCenter() {
  const grid = document.querySelector('.collections .grid');
  if (!grid) return;

  function apply() {
    if (window.innerWidth <= 768) {
      grid.style.display = 'flex';
      grid.style.flexDirection = 'column';
      grid.style.alignItems = 'center';   // centrato
      grid.style.justifyContent = 'flex-start';
      grid.style.gap = '0.8rem';
    } else {
      grid.style.removeProperty('display');
      grid.style.removeProperty('flex-direction');
      grid.style.removeProperty('align-items');
      grid.style.removeProperty('justify-content');
      grid.style.removeProperty('gap');
    }
  }

  apply();
  window.addEventListener('resize', apply);
})();
