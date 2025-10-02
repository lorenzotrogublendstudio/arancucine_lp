// Gestione semplice del form
/*document.getElementById("contactForm").addEventListener("submit", function(e) {
  e.preventDefault();
  alert("Grazie per averci contattato! Ti risponderemo al più presto.");
  this.reset();
});*/

const modal = document.getElementById('modalForm');
const closeModal = document.getElementById('closeModal');

// Seleziona tutti i bottoni che aprono la modale
const openButtons = document.querySelectorAll('.openModalBtn');

openButtons.forEach(btn => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    modal.classList.add('show');
    modal.classList.remove('hide');
  });
});

closeModal.addEventListener('click', () => {
  modal.classList.add('hide');
  setTimeout(() => {
    modal.classList.remove('show');
  }, 400);
});

// Chiudi cliccando fuori dal contenuto
window.addEventListener('click', (e) => {
  if (e.target === modal) {
    modal.classList.add('hide');
    setTimeout(() => {
      modal.classList.remove('show');
    }, 400);
  }
});
